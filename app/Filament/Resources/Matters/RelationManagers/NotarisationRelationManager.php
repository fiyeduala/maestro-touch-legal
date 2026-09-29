<?php

namespace App\Filament\Resources\Matters\RelationManagers;

use App\Domain\Notarisation\NotarisationHandoffs;
use App\Domain\Operations\Settings;
use App\Filament\Support\DomainActions;
use App\Models\NotarisationHandoff;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Manual Naija Virtual Notary handoffs: consent first, then staff keep the status and NVN reference current (D40). */
class NotarisationRelationManager extends MatterRelationManager
{
    protected static string $relationship = 'notarisationHandoffs';

    protected static ?string $title = 'Notarisation (NVN)';

    public function table(Table $table): Table
    {
        $tz = config('app.firm_timezone');

        return $table
            ->description('Handled by hand through Naija Virtual Notary. Nothing is sent to NVN from here: share only what the client agreed to.')
            ->emptyStateHeading('No notarisation arranged')
            ->columns([
                TextColumn::make('document_description')->label('Document')->wrap()
                    ->description(fn (NotarisationHandoff $record) => 'Consent: '.(NotarisationHandoff::CONSENT_METHODS[$record->consent_method] ?? $record->consent_method)
                        .' · '.$record->consent_recorded_at->timezone($tz)->format('j M Y')),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (string $state) => NotarisationHandoff::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        'completed' => 'success', 'cancelled' => 'gray', default => 'warning',
                    })
                    ->description(fn (NotarisationHandoff $record) => $record->status_note),
                TextColumn::make('external_reference')->label('NVN reference')->placeholder('—')->copyable(),
                TextColumn::make('status_changed_at')->label('Updated')->dateTime('j M Y H:i', $tz),
            ])
            ->headerActions([
                Action::make('openNvn')
                    ->label('Open Naija Virtual Notary')
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->color('gray')
                    ->url(fn () => (string) Settings::get('firm.nvn_url'), shouldOpenInNewTab: true),
                Action::make('record')
                    ->label('Arrange notarisation')
                    ->icon(Heroicon::OutlinedPlus)
                    ->visible(fn () => $this->open() && $this->allowed('update'))
                    ->schema([
                        TextInput::make('document_description')->label('Document to notarise')->required()->maxLength(500),
                        Select::make('consent_method')->label('How the client consented')->options(NotarisationHandoff::CONSENT_METHODS)->required(),
                        Textarea::make('consent_note')->label('Where the consent is kept')
                            ->helperText('For example "Email of 3 Oct from the client", or the date of the portal message.')->maxLength(500)->rows(2),
                        Checkbox::make('consent_confirmed')
                            ->label('The client has agreed to this document being shared with Naija Virtual Notary for notarisation.')->accepted(),
                    ])
                    ->action(fn (Action $action, array $data) => DomainActions::run($action,
                        fn () => app(NotarisationHandoffs::class)->record($this->matter(), $data, $this->me()), 'Consent recorded')),
            ])
            ->recordActions([
                Action::make('status')
                    ->label('Update')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->visible(fn (NotarisationHandoff $record) => ! $record->isFinal() && $this->allowed('update'))
                    ->fillForm(fn (NotarisationHandoff $record) => ['status' => $record->status, 'external_reference' => $record->external_reference])
                    ->schema(fn (NotarisationHandoff $record) => [
                        Select::make('status')->required()->options(array_intersect_key(NotarisationHandoff::STATUSES,
                            array_flip([$record->status, ...NotarisationHandoff::NEXT[$record->status]]))),
                        TextInput::make('external_reference')->label('NVN reference')->maxLength(100),
                        Textarea::make('note')->label('Internal note')->helperText('Required when cancelling. Not shown to the client.')->maxLength(500)->rows(2),
                    ])
                    ->action(fn (Action $action, NotarisationHandoff $record, array $data) => DomainActions::run($action,
                        fn () => app(NotarisationHandoffs::class)->updateStatus($record, $data['status'], $data['external_reference'] ?? null, $data['note'] ?? null, $this->me()),
                        'Notarisation updated')),
            ]);
    }
}
