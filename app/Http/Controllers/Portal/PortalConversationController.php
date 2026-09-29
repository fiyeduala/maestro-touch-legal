<?php

namespace App\Http\Controllers\Portal;

use App\Domain\Communication\Conversations;
use App\Domain\Documents\UploadGuard;
use App\Domain\RuleViolation;
use App\Http\Controllers\Controller;
use App\Models\Matter;
use App\Models\Message;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * The client side of the matter conversation. Only the "client" audience is ever loaded here:
 * internal notes are never queried by this controller. Posting goes through Conversations, which
 * refuses full administrators and anyone who is not a current contact of the matter's client.
 */
class PortalConversationController extends Controller
{
    public function __construct(private Conversations $conversations) {}

    /** Every matter conversation the user can see, with unread counts. */
    public function index(Request $request): View
    {
        $user = $request->user();
        $matters = Matter::whereIn('client_id', $user->clients()->pluck('clients.id'))
            ->orderByRaw("status = 'closed'")->latest('opened_at')->get();
        $latest = Message::clientVisible()->whereIn('matter_id', $matters->modelKeys())
            ->selectRaw('matter_id, max(id) as last_id, max(created_at) as last_at')->groupBy('matter_id')->get()->keyBy('matter_id');

        return view('portal.messages', [
            'matters' => $matters->sortByDesc(fn (Matter $m) => $latest[$m->id]->last_id ?? 0)->values(),
            'latest' => $latest,
            'unread' => $this->conversations->clientUnread($user),
        ]);
    }

    /** Newer messages since ?after= (polling), as rendered HTML so the page and the no-JS view match. */
    public function poll(Request $request, Matter $matter): JsonResponse
    {
        Gate::authorize('viewAsClient', $matter);
        $after = max(0, (int) $request->query('after'));
        $messages = $this->conversations->thread($matter, 'client', afterId: $after);
        $this->conversations->markRead($messages, $request->user());

        return response()->json([
            'html' => $messages->map(fn (Message $m) => view('portal.partials.message', ['message' => $m, 'user' => $request->user()])->render())->implode(''),
            'last_id' => $messages->last()?->id ?? $after,
            // Which of the user's recent messages the firm has now seen.
            'read_ids' => array_keys($this->conversations->readByOtherSide(
                $this->conversations->thread($matter, 'client', limit: 30)->where('sender_is_client', true))),
        ])->header('Cache-Control', 'no-store');
    }

    public function store(Request $request, Matter $matter): RedirectResponse|JsonResponse
    {
        Gate::authorize('actAsClient', $matter);
        if ($request->user()->isStaff()) {
            abort(403, 'Staff accounts reply from the staff panel.');
        }
        $request->validate([
            'body' => ['nullable', 'string', 'max:'.Conversations::MAX_LENGTH, 'required_without:file'],
            'file' => ['nullable', ...UploadGuard::rules()],
        ], [
            'body.required_without' => 'Write a message first.',
            'file.extensions' => 'Attach a PDF, Word (.docx) or image file (JPG, PNG, WebP).',
            'file.max' => 'Files must be '.intdiv(UploadGuard::MAX_KILOBYTES, 1024).' MB or smaller.',
        ]);

        try {
            $message = $this->conversations->send($matter, $request->user(), 'client', (string) $request->input('body'), $request->file('file'));
        } catch (RuleViolation $e) {
            return $request->expectsJson()
                ? response()->json(['message' => $e->getMessage()], 422)
                : back()->withInput()->withErrors(['body' => $e->getMessage()])->withFragment('messages');
        }

        if ($request->expectsJson()) {
            return response()->json(['id' => $message->id]);
        }

        return redirect()->to(route('portal.matters.show', $matter).'#messages')->with('status', 'Your message has been sent.');
    }

    /** @return array{messages: Collection, older: ?int, readReceipts: array} data for the chat on the matter page */
    public function forMatterPage(Matter $matter, Request $request): array
    {
        $before = $request->integer('before') ?: null;
        $messages = $this->conversations->thread($matter, 'client', beforeId: $before);
        $this->conversations->markRead($messages, $request->user());
        $oldest = $messages->first()?->id;

        return [
            'messages' => $messages,
            'older' => $oldest && Message::where('matter_id', $matter->id)->where('audience', 'client')->where('id', '<', $oldest)->exists() ? $oldest : null,
            'readReceipts' => $this->conversations->readByOtherSide($messages),
        ];
    }
}
