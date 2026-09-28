<?php

namespace App\Filament\Resources\Posts\Pages;

use App\Domain\Content\PostEditor;
use App\Filament\Concerns\AuditsRecordChanges;
use App\Filament\Resources\Posts\PostResource;
use App\Models\Post;
use App\Policies\PostPolicy;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

class EditPost extends EditRecord
{
    use AuditsRecordChanges;

    protected static string $resource = PostResource::class;

    private ?string $slugBeforeSave = null;

    private bool $wasLive = false;

    private function post(): Post
    {
        /** @var Post */
        return $this->getRecord();
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->slugBeforeSave = $this->post()->getOriginal('slug');
        $this->wasLive = in_array($this->post()->getOriginal('status'), PostPolicy::LIVE_STATUSES, true);

        return app(PostEditor::class)->prepare($data, auth()->user(), $this->post());
    }

    protected function afterSave(): void
    {
        app(PostEditor::class)->afterSave($this->post(), auth()->user(), 'edited', $this->slugBeforeSave, $this->wasLive);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('preview')
                ->icon(Heroicon::OutlinedEye)
                ->color('gray')
                ->url(fn () => route('preview.post', $this->post()), shouldOpenInNewTab: true),
            Action::make('view')
                ->label('View on site')
                ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                ->color('gray')
                ->visible(fn () => $this->post()->isVisible())
                ->url(fn () => $this->post()->url(), shouldOpenInNewTab: true),
            PostResource::deleteAction(DeleteAction::make()),
        ];
    }
}
