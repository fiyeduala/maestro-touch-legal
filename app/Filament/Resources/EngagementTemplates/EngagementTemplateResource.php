<?php

namespace App\Filament\Resources\EngagementTemplates;

use App\Filament\Resources\EngagementTemplates\Pages\CreateEngagementTemplate;
use App\Filament\Resources\EngagementTemplates\Pages\EditEngagementTemplate;
use App\Filament\Resources\EngagementTemplates\Pages\ListEngagementTemplates;
use App\Models\EngagementTemplate;
use App\Models\Service;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
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
use Illuminate\Support\HtmlString;
use UnitEnum;

/**
 * Starting text for engagement terms. Staff edit the prepared copy per client; the wording itself
 * is the firm's and must be approved by the owner before use.
 */
class EngagementTemplateResource extends Resource
{
    protected static ?string $model = EngagementTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentDuplicate;

    protected static string|UnitEnum|null $navigationGroup = 'Firm setup';

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        $placeholders = collect(EngagementTemplate::PLACEHOLDERS)
            ->map(fn (string $label, string $token) => '<code>'.e($token).'</code> '.e($label))->implode('<br>');

        return $schema->components([
            Section::make()->columnSpanFull()->schema([
                Grid::make(3)->schema([
                    TextInput::make('name')->required()->maxLength(190)->columnSpan(2),
                    Select::make('service_id')->label('For service')->placeholder('Any service')
                        ->options(fn () => Service::orderBy('name')->pluck('name', 'id')),
                ]),
                Toggle::make('is_active')->label('Available when preparing terms')->default(true),
                RichEditor::make('body')->label('Terms')->required()
                    ->toolbarButtons([['bold', 'italic', 'underline', 'link'], ['h2', 'h3'], ['bulletList', 'orderedList', 'blockquote'], ['undo', 'redo']])
                    ->helperText(new HtmlString('Placeholders filled in when terms are prepared:<br>'.$placeholders
                        .'<br>Changing the text raises the version. Terms already prepared keep their own copy.')),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('service'))
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('service.name')->label('Service')->placeholder('Any'),
                TextColumn::make('version')->prefix('v'),
                IconColumn::make('is_active')->label('Active')->boolean(),
                TextColumn::make('updated_at')->label('Updated')->since(),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEngagementTemplates::route('/'),
            'create' => CreateEngagementTemplate::route('/create'),
            'edit' => EditEngagementTemplate::route('/{record}/edit'),
        ];
    }
}
