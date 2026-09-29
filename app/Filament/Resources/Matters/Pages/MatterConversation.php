<?php

namespace App\Filament\Resources\Matters\Pages;

use App\Domain\Communication\Conversations;
use App\Domain\Documents\UploadGuard;
use App\Domain\RuleViolation;
use App\Filament\Resources\Matters\MatterResource;
use App\Models\Matter;
use App\Models\Message;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use Livewire\WithFileUploads;

/**
 * The staff side of a matter conversation (spec §8): the client chat next to the internal notes.
 * Internal notes are shown in a separate, clearly marked pane and are never sent to the client.
 * Polls while the tab is visible; nothing here edits or deletes a message.
 *
 * @property-read Matter $record
 */
class MatterConversation extends Page
{
    use InteractsWithRecord;
    use WithFileUploads;

    protected static string $resource = MatterResource::class;

    protected string $view = 'filament.matter-conversation';

    public const PAGE_SIZE = 30;

    public int $clientLimit = self::PAGE_SIZE;

    public int $internalLimit = self::PAGE_SIZE;

    public string $clientBody = '';

    public string $internalBody = '';

    public $clientFile = null;

    public $internalFile = null;

    public string $noteAudience = 'internal';

    public string $noteChannel = 'phone';

    public string $noteBody = '';

    public ?int $amendingId = null;

    public string $amendBody = '';

    public static function canAccess(array $parameters = []): bool
    {
        $record = $parameters['record'] ?? null;
        $user = auth()->user();

        return $user && $user->isStaff() && $record instanceof Matter && $user->can('view', $record);
    }

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
    }

    /** Livewire restores the record without its relations. */
    public function hydrate(): void
    {
        $this->record->loadMissing('client');
    }

    protected function resolveRecord(int|string $key): Matter
    {
        return MatterResource::getEloquentQuery()->with('client')->findOrFail($key);
    }

    public function getTitle(): string|Htmlable
    {
        return "Conversation · {$this->record->reference}";
    }

    public function getSubheading(): ?string
    {
        return "{$this->record->title} · {$this->record->client->display_name}";
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('matter')->label('Back to matter')->color('gray')->icon('heroicon-o-arrow-left')
                ->url(MatterResource::getUrl('view', ['record' => $this->record])),
        ];
    }

    public function loadOlder(string $audience): void
    {
        $audience === 'internal' ? $this->internalLimit += self::PAGE_SIZE : $this->clientLimit += self::PAGE_SIZE;
    }

    public function send(string $audience): void
    {
        $audience = $audience === 'internal' ? 'internal' : 'client';
        $body = $audience === 'internal' ? 'internalBody' : 'clientBody';
        $file = $audience === 'internal' ? 'internalFile' : 'clientFile';
        $this->validate([
            $body => ['nullable', 'string', 'max:'.Conversations::MAX_LENGTH, 'required_without:'.$file],
            $file => ['nullable', ...UploadGuard::rules()],
        ], [
            "{$body}.required_without" => 'Write a message first.',
            "{$file}.extensions" => 'Attach a PDF, Word (.docx) or image file (JPG, PNG, WebP).',
        ]);

        if ($this->attempt(fn (Conversations $chat) => $chat->send($this->record, auth()->user(), $audience, $this->{$body}, $this->{$file}),
            $audience === 'internal' ? 'Internal note saved' : 'Message sent')) {
            $this->reset($body, $file);
        }
    }

    public function recordCall(): void
    {
        $this->validate([
            'noteAudience' => ['required', 'in:client,internal'],
            'noteChannel' => ['required', 'in:'.implode(',', array_keys(Message::CHANNELS))],
            'noteBody' => ['required', 'string', 'max:'.Conversations::MAX_LENGTH],
        ]);
        if ($this->attempt(fn (Conversations $chat) => $chat->callNote($this->record, auth()->user(), $this->noteAudience, $this->noteChannel, $this->noteBody),
            $this->noteAudience === 'internal' ? 'Conversation note saved (internal)' : 'Conversation note added to the client chat')) {
            $this->reset('noteBody');
        }
    }

    public function startAmend(int $id): void
    {
        $this->amendingId = $id;
        $this->amendBody = '';
    }

    public function cancelAmend(): void
    {
        $this->reset('amendingId', 'amendBody');
    }

    public function amend(): void
    {
        $this->validate(['amendBody' => ['required', 'string', 'max:'.Conversations::MAX_LENGTH]]);
        $original = Message::where('matter_id', $this->record->id)->findOrFail($this->amendingId);
        if ($this->attempt(fn (Conversations $chat) => $chat->amend($original, auth()->user(), $this->amendBody), 'Correction added')) {
            $this->cancelAmend();
        }
    }

    /** Renders both panes and marks what is shown as read by this staff member. */
    protected function getViewData(): array
    {
        $chat = app(Conversations::class);
        $client = $chat->thread($this->record, 'client', limit: $this->clientLimit);
        $internal = $chat->thread($this->record, 'internal', limit: $this->internalLimit);
        $chat->markRead($client->concat($internal), auth()->user());

        return [
            'clientMessages' => $client,
            'internalMessages' => $internal,
            'clientHasOlder' => $this->hasOlder($client, 'client'),
            'internalHasOlder' => $this->hasOlder($internal, 'internal'),
            'readReceipts' => $chat->readByOtherSide($client),
            'closed' => $this->record->isClosed(),
            'channels' => Message::CHANNELS,
            'accept' => UploadGuard::acceptAttribute(),
            'tz' => config('app.firm_timezone'),
        ];
    }

    private function hasOlder(Collection $messages, string $audience): bool
    {
        return $messages->isNotEmpty()
            && Message::where('matter_id', $this->record->id)->where('audience', $audience)->where('id', '<', $messages->first()->id)->exists();
    }

    private function attempt(callable $callback, string $success): bool
    {
        try {
            app()->call($callback);
        } catch (RuleViolation|AuthorizationException $e) {
            Notification::make()->danger()->title('Not sent')->body($e->getMessage())->send();

            return false;
        }
        Notification::make()->success()->title($success)->send();

        return true;
    }
}
