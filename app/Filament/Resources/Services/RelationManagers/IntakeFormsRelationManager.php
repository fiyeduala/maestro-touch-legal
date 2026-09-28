<?php

namespace App\Filament\Resources\Services\RelationManagers;

use App\Domain\Intake\ServiceCatalogue;
use App\Filament\Support\DomainActions;
use App\Models\IntakeForm;
use App\Models\Service;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Intake form versions. Editing always works on a draft: a published version is never changed,
 * so the questions an enquirer answered stay on record exactly as they were asked.
 */
class IntakeFormsRelationManager extends RelationManager
{
    protected static string $relationship = 'intakeForms';

    protected static ?string $title = 'Intake questions';

    public static function canViewForRecord(\Illuminate\Database\Eloquent\Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('update', $ownerRecord) ?? false;
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    private function service(): Service
    {
        /** @var Service */
        return $this->getOwnerRecord();
    }

    /** The newest version's questions, ready for the repeater (choices one per line). */
    private function latestFields(): array
    {
        return collect($this->service()->intakeForms()->first()?->fields ?? [])
            ->map(fn (array $f) => [...$f, 'options' => implode("\n", $f['options'] ?? [])])->all();
    }

    public function table(Table $table): Table
    {
        $tz = config('app.firm_timezone');

        return $table
            ->description('Questions asked on the public enquiry form for this service, in addition to name, contact details and a summary.')
            ->columns([
                TextColumn::make('version')->prefix('v'),
                TextColumn::make('fields')->label('Questions')
                    ->state(fn (IntakeForm $record) => collect($record->fields)->pluck('label')->all())
                    ->listWithLineBreaks()->bulleted()->limitList(6)->expandableLimitedList(),
                TextColumn::make('published_at')->label('Published')->dateTime('j M Y H:i', $tz)->placeholder('Draft'),
            ])
            ->headerActions([
                Action::make('edit')
                    ->label('Edit questions')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->visible(fn () => auth()->user()->can('update', $this->service()))
                    ->modalWidth('5xl')
                    ->modalDescription('Saving creates or updates a draft. Nothing changes on the website until you publish it.')
                    ->fillForm(fn () => ['fields' => $this->latestFields()])
                    ->schema([
                        Repeater::make('fields')->hiddenLabel()->reorderable()->defaultItems(0)->addActionLabel('Add question')
                            ->itemLabel(fn (array $state) => $state['label'] ?? null)->collapsible()
                            ->schema([
                                Grid::make(3)->schema([
                                    TextInput::make('label')->label('Question')->required()->maxLength(190)->columnSpan(2),
                                    Select::make('type')->options(IntakeForm::FIELD_TYPES)->required()->default('text')->live(),
                                ]),
                                Grid::make(3)->schema([
                                    TextInput::make('key')->maxLength(40)->helperText('Generated from the question if blank.'),
                                    TextInput::make('help')->label('Hint shown under the question')->maxLength(250)->columnSpan(2),
                                ]),
                                Textarea::make('options')->label('Choices (one per line)')->rows(3)
                                    ->visible(fn (Get $get) => $get('type') === 'select')->required(fn (Get $get) => $get('type') === 'select'),
                                Toggle::make('required'),
                            ]),
                    ])
                    ->action(fn (Action $action, array $data) => DomainActions::run($action,
                        fn () => app(ServiceCatalogue::class)->saveFormDraft($this->service(), $data['fields'] ?? [], auth()->user()), 'Draft saved')),
            ])
            ->recordActions([
                Action::make('publish')
                    ->icon(Heroicon::OutlinedGlobeAlt)
                    ->color('success')
                    ->visible(fn (IntakeForm $record) => ! $record->isPublished() && auth()->user()->can('update', $this->service()))
                    ->requiresConfirmation()
                    ->modalDescription('New enquiries for this service will be asked these questions. This version cannot be edited afterwards.')
                    ->action(fn (Action $action, IntakeForm $record) => DomainActions::run($action,
                        fn () => app(ServiceCatalogue::class)->publishForm($record, auth()->user()), 'Published')),
            ]);
    }
}
