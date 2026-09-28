<?php

namespace App\Http\Controllers\Portal;

use App\Domain\Documents\Documents;
use App\Domain\Documents\UploadGuard;
use App\Domain\Engagement\Engagements;
use App\Domain\Engagement\OfferStatus;
use App\Domain\Engagement\Quotations;
use App\Domain\Matters\MatterStatus;
use App\Domain\RuleViolation;
use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\DocumentRequest;
use App\Models\Engagement;
use App\Models\Matter;
use App\Models\Quotation;
use App\Models\User;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * The client's own matters, documents, quotations and engagement terms. Clients see only
 * client-visible events, released document versions and their own uploads; internal drafts,
 * notes and tasks never reach these pages. Every decision goes through the domain services,
 * which refuse full administrators acting as a client.
 */
class PortalWorkController extends Controller
{
    /** Items waiting for this client user, across every client record they may act for. */
    public static function attention(User $user): array
    {
        $clientIds = $user->clients()->pluck('clients.id');
        $openMatter = fn (Builder $query) => $query->whereIn('client_id', $clientIds)->where('status', '!=', MatterStatus::Closed->value);

        return [
            'quotations' => Quotation::whereIn('client_id', $clientIds)->where('status', OfferStatus::Sent->value)->latest()->get(),
            'engagements' => Engagement::whereIn('client_id', $clientIds)->where('status', OfferStatus::Sent->value)->latest()->get(),
            'drafts' => Document::where('is_deliverable', true)->whereNotNull('released_version_id')->whereNull('client_decision')
                ->whereHas('matter', $openMatter)->with('matter:id,reference,title')->latest('released_at')->get(),
            'requests' => DocumentRequest::open()->whereHas('matter', $openMatter)->with('matter:id,reference,title')->orderBy('due_on')->get(),
        ];
    }

    public function matter(Request $request, Matter $matter): View
    {
        Gate::authorize('viewAsClient', $matter);
        $matter->load('service');

        return view('portal.matter', [
            'matter' => $matter,
            'canAct' => Gate::allows('actAsClient', $matter),
            'events' => $matter->events()->clientVisible()->latest('created_at')->limit(100)->get(),
            'documents' => $matter->documents()->clientVisible()->with(['releasedVersion', 'currentVersion'])->latest('updated_at')->get(),
            'requests' => $matter->documentRequests()->open()->orderBy('due_on')->get(),
            'deadlines' => $matter->deadlines()->where('client_visible', true)->whereNull('completed_at')->orderBy('due_at')->get(),
            'accept' => UploadGuard::acceptAttribute(),
            'maxMb' => intdiv(UploadGuard::MAX_KILOBYTES, 1024),
        ]);
    }

    public function upload(Request $request, Matter $matter, Documents $documents): RedirectResponse
    {
        Gate::authorize('actAsClient', $matter);
        $data = $request->validate([
            'file' => ['required', ...UploadGuard::rules()],
            'title' => ['nullable', 'string', 'max:190'],
            'request_id' => ['nullable', 'integer'],
            'note' => ['nullable', 'string', 'max:2000'],
        ], ['file.extensions' => 'Upload a PDF, Word (.docx) or image file (JPG, PNG, WebP).', 'file.max' => 'Files must be '.intdiv(UploadGuard::MAX_KILOBYTES, 1024).' MB or smaller.']);

        return $this->attempt(fn () => $documents->clientUpload($matter, $request->file('file'), (string) ($data['title'] ?? ''),
            isset($data['request_id']) ? (int) $data['request_id'] : null, $data['note'] ?? null, $request->user()),
            'Your file has been uploaded and the firm has been notified.', 'file');
    }

    public function decideDocument(Request $request, Document $document, Documents $documents): RedirectResponse
    {
        Gate::authorize('decideAsClient', $document);
        $data = $request->validate([
            'version_id' => ['required', 'integer'],
            'decision' => ['required', Rule::in(['approved', 'changes_requested'])],
            'comment' => ['nullable', 'string', 'max:5000'],
        ]);

        return $this->attempt(fn () => $documents->clientDecision($document, (int) $data['version_id'], $data['decision'], $data['comment'] ?? null,
            $request->user(), $request->ip(), $request->userAgent()),
            $data['decision'] === 'approved' ? 'Thank you. Your approval has been recorded.' : 'Thank you. The firm has been asked to make changes.', 'decision_'.$document->id);
    }

    public function quotation(Quotation $quotation): View
    {
        Gate::authorize('viewAsClient', $quotation);
        $version = $quotation->currentVersion;

        return view('portal.quotation', [
            'quotation' => $quotation,
            'version' => $version,
            'canRespond' => Gate::allows('respondAsClient', $quotation),
            'money' => fn (int $minor) => Money::format($minor, $quotation->currency),
            'acceptStatement' => strtr(Quotations::ACCEPT_STATEMENT, [':version' => $version->version, ':total' => Money::format($version->total_minor, $quotation->currency)]),
        ]);
    }

    public function respondQuotation(Request $request, Quotation $quotation, Quotations $quotations): RedirectResponse
    {
        Gate::authorize('respondAsClient', $quotation);
        $data = $request->validate([
            'version_id' => ['required', 'integer'],
            'decision' => ['required', Rule::in(['accepted', 'declined'])],
            'comment' => ['nullable', 'string', 'max:2000'],
            'confirm' => [Rule::requiredIf($request->input('decision') === 'accepted'), 'nullable', 'accepted'],
        ], ['confirm.required' => 'Tick the box to confirm you accept.']);

        return $this->attempt(fn () => $quotations->respond($quotation, (int) $data['version_id'], $data['decision'], $request->user(),
            $data['comment'] ?? null, $request->ip(), $request->userAgent()),
            $data['decision'] === 'accepted' ? 'Thank you. Your acceptance has been recorded.' : 'Your response has been recorded.', 'decision');
    }

    public function engagement(Request $request, Engagement $engagement): View
    {
        Gate::authorize('viewAsClient', $engagement);
        $version = $engagement->currentVersion;

        return view('portal.engagement', [
            'engagement' => $engagement,
            'version' => $version,
            'canRespond' => Gate::allows('respondAsClient', $engagement),
            'acceptStatement' => strtr(Engagements::ACCEPT_STATEMENT, [
                ':name' => '[your name]', ':title' => $engagement->title, ':reference' => $engagement->reference, ':version' => $version?->version,
            ]),
        ]);
    }

    public function respondEngagement(Request $request, Engagement $engagement, Engagements $engagements): RedirectResponse
    {
        Gate::authorize('respondAsClient', $engagement);
        $accepting = $request->input('decision') === 'accepted';
        $data = $request->validate([
            'version_id' => ['required', 'integer'],
            'decision' => ['required', Rule::in(['accepted', 'declined'])],
            'signed_name' => [Rule::requiredIf($accepting), 'nullable', 'string', 'min:3', 'max:160'],
            'comment' => ['nullable', 'string', 'max:2000'],
            'confirm' => [Rule::requiredIf($accepting), 'nullable', 'accepted'],
        ], ['signed_name.required' => 'Type your full name to sign.', 'confirm.required' => 'Tick the box to confirm you accept.']);

        return $this->attempt(fn () => $engagements->respond($engagement, (int) $data['version_id'], $data['decision'], $data['signed_name'] ?? null,
            $data['comment'] ?? null, $request->user(), $request->ip(), $request->userAgent()),
            $accepting ? 'Thank you. Your signed acceptance has been recorded. The firm will confirm when your matter is open.' : 'Your response has been recorded.', 'decision');
    }

    private function attempt(callable $callback, string $success, string $errorKey): RedirectResponse
    {
        try {
            $callback();
        } catch (RuleViolation $e) {
            return back()->withInput()->withErrors([$errorKey => $e->getMessage()]);
        }

        return back()->with('status', $success);
    }
}
