<?php

namespace App\Domain\Recruitment;

use App\Domain\Identity\AccountAdministrationException;
use App\Domain\Identity\Invitations;
use App\Domain\Identity\Role;
use App\Domain\Operations\Audit;
use App\Domain\Operations\Settings;
use App\Models\StaffApplication;
use App\Models\StaffApplicationEvent;
use App\Models\StaffApplicationFile;
use App\Models\User;
use App\Notifications\Recruitment\ApplicationDecided;
use App\Notifications\Recruitment\ApplicationReceived;
use App\Notifications\Recruitment\InformationRequested;
use App\Notifications\Recruitment\NewApplicationForReview;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Join-our-legal-team workflow. Applicants have no account; a hashed token in
 * their confirmation email lets them respond to requests or withdraw.
 * Approval never grants access directly: it issues an invitation to a role the
 * reviewer selects explicitly.
 */
class StaffApplications
{
    public const CONSENT_VERSION = '2026-09-draft';

    public const DISK = 'confidential';

    public function __construct(private Invitations $invitations) {}

    /**
     * @param  array<string, mixed>  $data  validated form data
     * @param  list<UploadedFile>  $supporting
     * @return array{0: StaffApplication, 1: string} application and plaintext applicant token
     */
    public function submit(array $data, UploadedFile $cv, array $supporting = [], ?string $ip = null): array
    {
        $token = Str::random(48);
        $stored = [];

        try {
            $application = DB::transaction(function () use ($data, $cv, $supporting, $ip, $token, &$stored) {
                $application = StaffApplication::create([
                    'reference' => $this->nextReference(),
                    'status' => ApplicationStatus::Submitted,
                    'full_name' => $data['full_name'],
                    'email' => Str::lower($data['email']),
                    'phone' => $data['phone'],
                    'location' => $data['location'],
                    'professional_category' => $data['professional_category'],
                    'practice_areas' => array_values($data['practice_areas']),
                    'years_experience' => (int) $data['years_experience'],
                    'qualifications' => $data['qualifications'],
                    'professional_registration' => $data['professional_registration'] ?? null,
                    'statement' => $data['statement'] ?? null,
                    'consent_at' => now(),
                    'consent_version' => self::CONSENT_VERSION,
                    'applicant_token_hash' => StaffApplication::hashToken($token),
                    'submitted_ip' => $ip,
                ]);

                $event = $this->event($application, 'submitted', null, ApplicationStatus::Submitted, null, false, null);
                $stored[] = $this->storeFile($application, $cv, 'cv', $event);
                foreach ($supporting as $file) {
                    $stored[] = $this->storeFile($application, $file, 'supporting', $event);
                }

                Audit::record('staff_application.submitted', "Application {$application->reference} received from {$application->email}", $application);

                return $application;
            });
        } catch (\Throwable $e) {
            foreach ($stored as $path) {
                Storage::disk(self::DISK)->delete($path);
            }
            throw $e;
        }

        Notification::route('mail', [$application->email => $application->full_name])
            ->notify(new ApplicationReceived($application, $token));
        $this->notifyReviewers(new NewApplicationForReview($application));

        return [$application, $token];
    }

    public function transition(StaffApplication $application, ApplicationStatus $to, User $actor, ?string $reason = null): void
    {
        $this->authoriseReviewer($actor);

        DB::transaction(function () use ($application, $to, $actor, $reason) {
            $application = StaffApplication::lockForUpdate()->findOrFail($application->id);
            $from = $application->status;
            if (! $from->canTransitionTo($to)) {
                throw new AccountAdministrationException("An application that is {$from->label()} cannot move to {$to->label()}.");
            }
            if (in_array($to, [ApplicationStatus::Approved, ApplicationStatus::MoreInfoRequested], true)) {
                throw new \LogicException('Use approve() or requestInformation() for this transition.');
            }

            $application->status = $to;
            if (in_array($to, [ApplicationStatus::Declined, ApplicationStatus::Withdrawn], true)) {
                $application->decided_at = now();
                $application->decided_by = $actor->id;
                $application->decision_reason = $reason;
            }
            $application->save();

            $this->event($application, 'status_changed', $from, $to, $reason, true, $actor);
            Audit::record('staff_application.status_changed', "{$application->reference}: {$from->label()} → {$to->label()}", $application,
                ['before' => ['status' => $from->value], 'after' => ['status' => $to->value]], actor: $actor);
        });

        if ($to === ApplicationStatus::Declined) {
            $application->refresh();
            Notification::route('mail', [$application->email => $application->full_name])
                ->notify(new ApplicationDecided($application));
        }
    }

    public function assign(StaffApplication $application, ?User $reviewer, User $actor): void
    {
        $this->authoriseReviewer($actor);
        if ($reviewer && ! $reviewer->isFullAdministrator()) {
            throw new AccountAdministrationException('Applications can only be assigned to a Technical Administrator or Firm Principal.');
        }

        $application->update(['assigned_reviewer_id' => $reviewer?->id]);
        Audit::record('staff_application.assigned', "{$application->reference} assigned to ".($reviewer?->name ?? 'nobody'), $application, actor: $actor);
    }

    public function addNote(StaffApplication $application, string $note, User $actor): void
    {
        $this->authoriseReviewer($actor);
        $this->event($application, 'note', null, null, $note, true, $actor);
        Audit::record('staff_application.note_added', "Internal note on {$application->reference}", $application, actor: $actor);
    }

    public function requestInformation(StaffApplication $application, string $message, User $actor): void
    {
        $this->authoriseReviewer($actor);

        DB::transaction(function () use ($application, $message, $actor) {
            $application = StaffApplication::lockForUpdate()->findOrFail($application->id);
            $from = $application->status;
            if (! $from->canTransitionTo(ApplicationStatus::MoreInfoRequested)) {
                throw new AccountAdministrationException("An application that is {$from->label()} cannot receive an information request.");
            }
            $application->update(['status' => ApplicationStatus::MoreInfoRequested]);
            $this->event($application, 'info_requested', $from, ApplicationStatus::MoreInfoRequested, $message, false, $actor);
            Audit::record('staff_application.info_requested', "Requested more information on {$application->reference}", $application, actor: $actor);
        });

        // Rotate the applicant token so the email carries a working link (the old one is never stored).
        $token = Str::random(48);
        $application->forceFill(['applicant_token_hash' => StaffApplication::hashToken($token)])->save();
        Notification::route('mail', [$application->email => $application->full_name])
            ->notify(new InformationRequested($application->refresh(), $message, $token));
    }

    /** @param list<UploadedFile> $files */
    public function applicantRespond(StaffApplication $application, string $message, array $files = []): void
    {
        $stored = [];
        try {
            DB::transaction(function () use ($application, $message, $files, &$stored) {
                $application = StaffApplication::lockForUpdate()->findOrFail($application->id);
                if ($application->status !== ApplicationStatus::MoreInfoRequested) {
                    throw new AccountAdministrationException('This application is not currently waiting for information from you.');
                }
                $application->update(['status' => ApplicationStatus::UnderReview]);
                $event = $this->event($application, 'applicant_responded', ApplicationStatus::MoreInfoRequested, ApplicationStatus::UnderReview, $message, false, null);
                foreach ($files as $file) {
                    $stored[] = $this->storeFile($application, $file, 'supporting', $event);
                }
                Audit::record('staff_application.applicant_responded', "Applicant responded on {$application->reference}", $application);
            });
        } catch (\Throwable $e) {
            foreach ($stored as $path) {
                Storage::disk(self::DISK)->delete($path);
            }
            throw $e;
        }

        $this->notifyReviewers(new NewApplicationForReview($application->refresh(), responded: true));
    }

    public function applicantWithdraw(StaffApplication $application): void
    {
        DB::transaction(function () use ($application) {
            $application = StaffApplication::lockForUpdate()->findOrFail($application->id);
            if (! $application->status->canTransitionTo(ApplicationStatus::Withdrawn)) {
                throw new AccountAdministrationException('This application can no longer be withdrawn.');
            }
            $from = $application->status;
            $application->update(['status' => ApplicationStatus::Withdrawn, 'decided_at' => now()]);
            $this->event($application, 'status_changed', $from, ApplicationStatus::Withdrawn, 'Withdrawn by the applicant.', false, null);
            Audit::record('staff_application.withdrawn', "Applicant withdrew {$application->reference}", $application);
        });
    }

    /** Approval issues an invitation for the explicitly chosen staff role. */
    public function approve(StaffApplication $application, Role $role, User $actor, ?string $reason = null): void
    {
        $this->authoriseReviewer($actor);
        if (! $role->isStaff()) {
            throw new AccountAdministrationException('Choose a staff role for the approved applicant.');
        }

        DB::transaction(function () use ($application, $role, $actor, $reason) {
            $application = StaffApplication::lockForUpdate()->findOrFail($application->id);
            $from = $application->status;
            if (! $from->canTransitionTo(ApplicationStatus::Approved)) {
                throw new AccountAdministrationException("An application that is {$from->label()} cannot be approved.");
            }

            [$invitation] = $this->invitations->issue($application->email, $application->full_name, [$role], $actor, $application);

            $application->update([
                'status' => ApplicationStatus::Approved,
                'decided_at' => now(),
                'decided_by' => $actor->id,
                'decision_reason' => $reason,
                'approved_role' => $role->value,
                'invitation_id' => $invitation->id,
            ]);
            $this->event($application, 'status_changed', $from, ApplicationStatus::Approved, $reason, true, $actor);
            Audit::record('staff_application.approved', "Approved {$application->reference} as {$role->label()}", $application,
                ['after' => ['status' => 'approved', 'role' => $role->value]], actor: $actor);
        });
    }

    public function authoriseReviewer(User $actor): void
    {
        if (! $actor->isFullAdministrator()) {
            throw new AccountAdministrationException('Only a Technical Administrator or Firm Principal can review applications.');
        }
    }

    private function storeFile(StaffApplication $application, UploadedFile $file, string $kind, ?StaffApplicationEvent $event): string
    {
        $ext = strtolower($file->guessExtension() ?: $file->getClientOriginalExtension());
        $path = "staff-applications/{$application->id}/".Str::uuid().'.'.$ext;
        Storage::disk(self::DISK)->putFileAs(dirname($path), $file, basename($path));

        StaffApplicationFile::create([
            'staff_application_id' => $application->id,
            'kind' => $kind,
            'disk' => self::DISK,
            'path' => $path,
            'original_name' => Str::limit(basename($file->getClientOriginalName()), 200, ''),
            'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
            'size' => $file->getSize(),
            'sha256' => hash_file('sha256', $file->getRealPath()),
            'staff_application_event_id' => $event?->id,
            'created_at' => now(),
        ]);

        return $path;
    }

    private function event(StaffApplication $application, string $type, ?ApplicationStatus $from, ?ApplicationStatus $to, ?string $body, bool $internal, ?User $actor): StaffApplicationEvent
    {
        return StaffApplicationEvent::create([
            'staff_application_id' => $application->id,
            'type' => $type,
            'from_status' => $from?->value,
            'to_status' => $to?->value,
            'body' => $body,
            'is_internal' => $internal,
            'actor_id' => $actor?->id,
            'created_at' => now(),
        ]);
    }

    private function nextReference(): string
    {
        $year = now()->year;
        $count = StaffApplication::whereYear('created_at', $year)->lockForUpdate()->count();

        do {
            $count++;
            $reference = sprintf('APP-%d-%04d', $year, $count);
        } while (StaffApplication::where('reference', $reference)->exists());

        return $reference;
    }

    /** Notifies active full administrators. Configured admin addresses are added; none are invented. */
    private function notifyReviewers(object $notification): void
    {
        $reviewers = User::query()->active()->withActiveRole(...Role::fullAdministratorRoles())->get();
        Notification::send($reviewers, $notification);

        $extra = array_filter((array) Settings::get('notifications.admin_recipients'),
            fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL) && ! $reviewers->contains('email', strtolower($e)));
        foreach ($extra as $address) {
            Notification::route('mail', $address)->notify($notification);
        }

        if ($reviewers->isEmpty() && $extra === []) {
            Log::warning('Staff application notification had no recipients: no active administrators or configured admin addresses.');
        }
    }
}
