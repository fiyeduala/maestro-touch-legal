<?php

namespace App\Filament\Resources\Pages\Pages;

use App\Domain\Content\PageRevisions;
use App\Filament\Resources\Pages\PageResource;
use App\Models\Page;
use App\Models\PageRevision;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Mews\Purifier\Facades\Purifier;

/**
 * Edits the page's open draft (or starts one from the published text).
 * The Save button always creates a new draft revision; publishing is a separate, authorised step.
 */
class EditPage extends EditRecord
{
    protected static string $resource = PageResource::class;

    protected function resolveRecord(int|string $key): Model
    {
        return parent::resolveRecord($key)->load(['draftRevision', 'publishedRevision']);
    }

    private function page(): Page
    {
        /** @var Page */
        return $this->getRecord();
    }

    private function workingRevision(): ?PageRevision
    {
        return $this->page()->draftRevision ?? $this->page()->publishedRevision;
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $revision = $this->workingRevision();

        return [
            'title' => $revision?->title ?? $this->page()->title,
            'meta_title' => $revision?->meta_title,
            'meta_description' => $revision?->meta_description,
            'noindex' => (bool) $revision?->noindex,
            'content' => $revision?->content ?? [],
            'note' => null,
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $content = $data['content'] ?? [];
        if (isset($content['body']) && is_string($content['body'])) {
            $content['body'] = Purifier::clean($content['body'], 'content');
        }

        app(PageRevisions::class)->saveDraft($record, [
            'title' => $data['title'],
            'meta_title' => $data['meta_title'] ?? null,
            'meta_description' => $data['meta_description'] ?? null,
            'noindex' => (bool) ($data['noindex'] ?? false),
            'content' => $content,
        ], auth()->user(), $data['note'] ?? null);

        return $this->reloadPage();
    }

    private function reloadPage(): Page
    {
        $this->record = $this->resolveRecord($this->page()->getKey());

        return $this->record;
    }

    protected function getSavedNotification(): ?Notification
    {
        return Notification::make()->success()->title('Draft saved')
            ->body(auth()->user()->can('publish', $this->page())
                ? 'Preview it, then publish when ready. The live page has not changed.'
                : 'The live page has not changed. Someone with publishing rights needs to publish it.');
    }

    protected function getSaveFormAction(): Action
    {
        return parent::getSaveFormAction()->label('Save draft');
    }

    protected function getRedirectUrl(): ?string
    {
        return null;
    }

    protected function getHeaderActions(): array
    {
        $service = fn (): PageRevisions => app(PageRevisions::class);

        return [
            Action::make('preview')
                ->label(fn () => $this->page()->draft_revision_id ? 'Preview draft' : 'Preview')
                ->icon(Heroicon::OutlinedEye)
                ->color('gray')
                ->url(fn () => route('preview.page', ['page' => $this->page(), 'revision' => $this->workingRevision()]), shouldOpenInNewTab: true)
                ->visible(fn () => $this->workingRevision() !== null),

            Action::make('publish')
                ->label('Publish draft')
                ->icon(Heroicon::OutlinedGlobeAlt)
                ->authorize('publish')
                ->visible(fn () => $this->page()->draft_revision_id !== null)
                ->requiresConfirmation()
                ->modalDescription('The draft replaces the live page immediately. The current version stays in the history and can be restored.')
                ->action(function () use ($service) {
                    $service()->publish($this->page()->draftRevision, auth()->user());
                    $this->reloadPage();
                    $this->fillForm();
                    Notification::make()->success()->title('Published')->send();
                }),

            Action::make('discard')
                ->label('Discard draft')
                ->icon(Heroicon::OutlinedTrash)
                ->color('gray')
                ->authorize('update')
                ->visible(fn () => $this->page()->draft_revision_id !== null)
                ->requiresConfirmation()
                ->modalDescription('The unpublished changes are dropped. They remain in the revision history.')
                ->action(function () use ($service) {
                    $service()->discardDraft($this->page(), auth()->user());
                    $this->reloadPage();
                    $this->fillForm();
                    Notification::make()->success()->title('Draft discarded')->send();
                }),

            Action::make('unpublish')
                ->icon(Heroicon::OutlinedEyeSlash)
                ->color('danger')
                ->authorize('publish')
                ->visible(fn () => $this->page()->published_revision_id !== null && $this->page()->path !== '/')
                ->requiresConfirmation()
                ->modalDescription('The page will return “not found” to visitors until a revision is published again.')
                ->action(function () use ($service) {
                    $service()->unpublish($this->page(), auth()->user());
                    $this->reloadPage();
                    Notification::make()->success()->title('Unpublished')->send();
                }),
        ];
    }
}
