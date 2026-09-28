<?php

namespace App\Filament\Resources\Services;

use App\Filament\Resources\Services\Pages\CreateService;
use App\Filament\Resources\Services\Pages\EditService;
use App\Filament\Resources\Services\Pages\ListServices;
use App\Filament\Resources\Services\RelationManagers\IntakeFormsRelationManager;
use App\Models\Service;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** Services offered on the enquiry form, with their matter stages and intake questions. Full administrators only. */
class ServiceResource extends Resource
{
    protected static ?string $model = Service::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBriefcase;

    protected static string|UnitEnum|null $navigationGroup = 'Firm setup';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Service')->columnSpanFull()->schema([
                Grid::make(3)->schema([
                    TextInput::make('name')->required()->maxLength(190)->columnSpan(2),
                    TextInput::make('slug')->label('Web address key')->maxLength(80)
                        ->helperText('Leave blank to generate from the name.'),
                ]),
                Textarea::make('summary')->label('Short description (public)')->maxLength(1000)->rows(2),
                Grid::make(3)->schema([
                    Toggle::make('is_public')->label('Show on the enquiry form')->default(true),
                    Toggle::make('is_active')->label('Active')->default(true)
                        ->helperText('Inactive services cannot be chosen for new enquiries.'),
                    TextInput::make('sort')->numeric()->integer()->minValue(0)->maxValue(999)->default(0),
                ]),
            ]),
            Section::make('Matter stages')->columnSpanFull()
                ->description('The progress steps clients see on matters for this service. Leave empty to use the standard stages: '
                    .collect(Service::DEFAULT_STAGES)->pluck('label')->implode(' → ').'.')
                ->schema([
                    Repeater::make('stages')->hiddenLabel()->reorderable()->defaultItems(0)->addActionLabel('Add stage')
                        ->columns(2)
                        ->schema([
                            TextInput::make('label')->required()->maxLength(80),
                            TextInput::make('key')->maxLength(40)->helperText('Internal key; generated from the label if blank. Do not change keys in use.'),
                        ]),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->modifyQueryUsing(fn ($query) => $query->with('publishedForm')->withCount('intakeForms'))
            ->columns([
                TextColumn::make('name')->searchable(),
                IconColumn::make('is_public')->label('On enquiry form')->boolean(),
                IconColumn::make('is_active')->label('Active')->boolean(),
                TextColumn::make('publishedForm.version')->label('Published form')->prefix('v')->placeholder('None'),
                TextColumn::make('stages')->label('Stages')
                    ->state(fn (Service $record) => count($record->stageList()).($record->stages ? ' (custom)' : ' (standard)')),
                TextColumn::make('sort')->sortable(),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getRelations(): array
    {
        return [IntakeFormsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListServices::route('/'),
            'create' => CreateService::route('/create'),
            'edit' => EditService::route('/{record}/edit'),
        ];
    }
}
