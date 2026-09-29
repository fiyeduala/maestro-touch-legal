<?php

namespace App\Filament\Resources\Matters\Pages;

use App\Domain\Communication\Conversations;
use App\Filament\Resources\Consultations\ConsultationResource;
use App\Filament\Resources\Matters\MatterResource;
use App\Filament\Support\RefreshesRecord;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;

class ViewMatter extends ViewRecord
{
    use RefreshesRecord;

    protected static string $resource = MatterResource::class;

    protected function resolveRecord(int|string $key): Model
    {
        return parent::resolveRecord($key)->load(['client', 'service', 'enquiry', 'engagement', 'responsible.user', 'parties']);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('conversation')->label($this->conversationLabel())->icon(Heroicon::OutlinedChatBubbleLeftRight)->color('gray')
                ->url(MatterResource::getUrl('conversation', ['record' => $this->record])),
            ...$this->refreshingAfter([
                ...MatterResource::workActions(),
                ConsultationResource::scheduleAction(matter: $this->record)->visible(! $this->record->isClosed()),
            ]),
        ];
    }

    private function conversationLabel(): string
    {
        $unread = app(Conversations::class)->staffUnread(auth()->user())[$this->record->id] ?? null;
        $count = $unread ? $unread['client'] + $unread['internal'] : 0;

        return $count ? "Conversation ({$count} unread)" : 'Conversation';
    }
}
