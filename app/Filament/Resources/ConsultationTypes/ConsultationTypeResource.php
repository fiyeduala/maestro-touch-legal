<?php

namespace App\Filament\Resources\ConsultationTypes;

use App\Filament\Resources\ConsultationTypes\Pages\CreateConsultationType;
use App\Filament\Resources\ConsultationTypes\Pages\EditConsultationType;
use App\Filament\Resources\ConsultationTypes\Pages\ListConsultationTypes;
use App\Models\ConsultationType;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * The kinds of consultation clients can request (length and fee). Full administrators only.
 * A fee is shown to the client as information; payment itself is handled in billing (Phase 5).
 */
class ConsultationTypeResource extends Resource
{
    protected static ?string $model = ConsultationType::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static string|UnitEnum|null $navigationGroup = 'Firm setup';

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columnSpanFull()->schema([
                Grid::make(3)->schema([
                    TextInput::make('name')->required()->maxLength(120)->columnSpan(2),
                    TextInput::make('duration_minutes')->label('Length (minutes)')->required()->integer()->minValue(10)->maxValue(480)->default(30),
                ]),
                Textarea::make('description')->label('Description (shown to clients)')->maxLength(1000)->rows(2),
                Grid::make(3)->schema([
                    Toggle::make('is_free')->label('Free')->default(true)->live(),
                    TextInput::make('fee')->label('Fee')->placeholder('e.g. 25,000.00')
                        ->regex('/^[\d,]{1,17}(\.\d{1,2})?$/')
                        ->hidden(fn (Get $get) => (bool) $get('is_free'))->required(fn (Get $get) => ! $get('is_free')),
                    Select::make('currency')->options(Money::currencyOptions())
                        ->hidden(fn (Get $get) => (bool) $get('is_free'))->required(fn (Get $get) => ! $get('is_free')),
                ]),
                Grid::make(3)->schema([
                    Toggle::make('is_public')->label('Clients can request it')->default(true)
                        ->helperText('When off, only staff can book it.'),
                    Toggle::make('is_active')->label('Active')->default(true)
                        ->helperText('Inactive types cannot be booked.'),
                    TextInput::make('sort')->integer()->minValue(0)->maxValue(999)->default(0),
                ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('duration_minutes')->label('Length')->suffix(' min'),
                TextColumn::make('price')->state(fn (ConsultationType $record) => $record->priceLabel()),
                IconColumn::make('is_public')->label('Client bookable')->boolean(),
                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->recordActions([EditAction::make()]);
    }

    /** Form state for an existing type: the stored minor units shown as a decimal amount. */
    public static function fillFrom(ConsultationType $type): array
    {
        return $type->only(['name', 'description', 'duration_minutes', 'is_free', 'currency', 'is_public', 'is_active', 'sort'])
            + ['fee' => $type->fee_minor ? Money::toDecimal($type->fee_minor) : null];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListConsultationTypes::route('/'),
            'create' => CreateConsultationType::route('/create'),
            'edit' => EditConsultationType::route('/{record}/edit'),
        ];
    }
}
