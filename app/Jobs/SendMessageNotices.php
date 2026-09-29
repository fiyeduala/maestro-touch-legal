<?php

namespace App\Jobs;

use App\Domain\Clients\ClientContacts;
use App\Domain\Identity\Role;
use App\Domain\Operations\StaffNotifier;
use App\Models\Message;
use App\Models\User;
use App\Notifications\PortalUpdate;
use App\Notifications\StaffAlert;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * "You have a new message" emails for the client conversation, sent a few minutes after a message
 * so that anyone who has already read it in the portal gets nothing. One email per unread batch:
 * a recipient is not emailed again until they have read what they were told about.
 * Recipients and their access are worked out when the job runs, not when the message was sent.
 * The email never contains the message text.
 */
class SendMessageNotices implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(public int $messageId) {}

    public function handle(ClientContacts $contacts): void
    {
        $message = Message::with('matter.client')->find($this->messageId);
        if (! $message || $message->audience !== 'client' || $message->matter->isClosed()) {
            return;
        }
        $matter = $message->matter;

        if ($message->sender_is_client) {
            // The matter team; if nobody is assigned, the active full administrators.
            $team = $matter->activeTeam()->with('user')->get()->pluck('user')->filter(fn (?User $u) => $u?->isActive());
            if ($team->isEmpty()) {
                $team = User::active()->withActiveRole(...Role::fullAdministratorRoles())->get();
            }
            StaffNotifier::users($this->needingNotice($message, $team), new StaffAlert(
                "New client message on {$matter->reference}", 'The client has sent a message in the matter conversation.',
                "/admin/matters/{$matter->id}/conversation"));
        } else {
            $recipients = $this->needingNotice($message, $contacts->portalUsers($matter->client));
            foreach ($recipients as $user) {
                $user->notify(new PortalUpdate('You have a new message from Maestro Touch Legal',
                    'There is a new message for you in your client portal.', '/portal/messages'));
            }
        }
    }

    /** Recipients with an unread message up to this one who have not yet been told about that unread batch. */
    private function needingNotice(Message $message, Collection $users): Collection
    {
        return $users->filter(function (User $user) use ($message) {
            return DB::transaction(function () use ($user, $message) {
                $firstUnread = Message::where('matter_id', $message->matter_id)->where('audience', 'client')
                    ->where('id', '<=', $message->id)->where('sender_id', '!=', $user->id)
                    ->whereDoesntHave('reads', fn ($q) => $q->where('user_id', $user->id))
                    ->min('id');
                if (! $firstUnread) {
                    return false;
                }
                $notice = DB::table('message_notices')->where('matter_id', $message->matter_id)->where('user_id', $user->id)->lockForUpdate()->first();
                if ($notice && $notice->last_message_id >= $firstUnread) {
                    return false;
                }
                DB::table('message_notices')->updateOrInsert(
                    ['matter_id' => $message->matter_id, 'user_id' => $user->id],
                    ['last_message_id' => $message->id, 'sent_at' => now()],
                );

                return true;
            });
        })->values();
    }
}
