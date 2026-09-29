<?php

namespace App\Filament\Resources\ClientFunds\Pages;

use App\Domain\Billing\ClientFunds;
use App\Filament\Resources\ClientFunds\ClientFundEntryResource;
use App\Filament\Support\DomainActions;
use App\Filament\Support\RefreshesRecord;
use App\Models\ClientFundEntry;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;

class ViewClientFundEntry extends ViewRecord
{
    use RefreshesRecord;

    protected static string $resource = ClientFundEntryResource::class;

    protected function resolveRecord(int|string $key): Model
    {
        return parent::resolveRecord($key)->load(['client', 'matter', 'createdBy', 'reverses', 'reversedBy']);
    }

    protected function getHeaderActions(): array
    {
        return $this->refreshingAfter([
            Action::make('reverse')
                ->icon(Heroicon::OutlinedArrowUturnLeft)
                ->color('danger')
                ->authorize('reverse')
                ->visible(fn (ClientFundEntry $record) => $record->type !== 'reversal' && $record->reversedBy === null)
                ->modalDescription('Adds an opposite entry dated today. The original stays on the statement.')
                ->schema([Textarea::make('reason')->required()->maxLength(2000)])
                ->action(fn (Action $action, ClientFundEntry $record, array $data) => DomainActions::run($action,
                    fn () => app(ClientFunds::class)->reverse($record, $data['reason'], auth()->user()), 'Entry reversed')),
        ]);
    }
}
