<?php

namespace App\Filament\Resources\Redirects;

use App\Domain\Operations\Audit;
use App\Filament\Resources\Redirects\Pages\CreateRedirect;
use App\Filament\Resources\Redirects\Pages\EditRedirect;
use App\Filament\Resources\Redirects\Pages\ListRedirects;
use App\Http\Middleware\ApplyRedirects;
use App\Models\Redirect;
use BackedEnum;
use Closure;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class RedirectResource extends Resource
{
    protected static ?string $model = Redirect::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUturnRight;

    protected static string|UnitEnum|null $navigationGroup = 'Website';

    protected static ?int $navigationSort = 60;

    protected static ?string $recordTitleAttribute = 'from_path';

    public const STATUS_OPTIONS = [
        301 => '301 Moved permanently',
        302 => '302 Temporary',
        410 => '410 Gone (no destination)',
    ];

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->columnSpanFull()->schema([
                TextInput::make('from_path')
                    ->label('Old path')
                    ->helperText('The path people or search engines still request, e.g. /old-page/. Query strings are ignored.')
                    ->required()
                    ->maxLength(255)
                    ->startsWith('/')
                    ->rules([
                        fn (?Redirect $record): Closure => function (string $attribute, $value, Closure $fail) use ($record) {
                            $path = Redirect::normalise((string) $value);
                            if (ApplyRedirects::isProtected($path)) {
                                $fail('This path belongs to the application and cannot be redirected.');
                            }
                            if (Redirect::where('from_path', $path)->when($record, fn ($q) => $q->whereKeyNot($record->getKey()))->exists()) {
                                $fail('A redirect for this path already exists.');
                            }
                        },
                    ]),
                Select::make('status_code')
                    ->label('Type')
                    ->options(self::STATUS_OPTIONS)
                    ->default(301)
                    ->required()
                    ->live(),
                TextInput::make('to_path')
                    ->label('Destination')
                    ->helperText('A path on this site (e.g. /blog/) or a full https:// address.')
                    ->maxLength(255)
                    ->required(fn (Get $get) => (int) $get('status_code') !== 410)
                    ->hidden(fn (Get $get) => (int) $get('status_code') === 410)
                    ->regex('#^(/|https?://)#i')
                    ->different('from_path')
                    ->columnSpanFull(),
                Text::make(fn (?Redirect $record) => $record
                    ? "Source: {$record->source}. Used {$record->hits} time(s)".($record->last_hit_at ? ', last on '.$record->last_hit_at->timezone(config('app.firm_timezone'))->format('j M Y H:i') : '').'.'
                    : 'New redirects apply immediately after saving.')
                    ->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('from_path')
            ->columns([
                TextColumn::make('from_path')->label('Old path')->searchable()->sortable(),
                TextColumn::make('to_path')->label('Destination')->searchable()->placeholder('—'),
                TextColumn::make('status_code')->label('Type')->badge()
                    ->color(fn (int $state) => $state === 410 ? 'danger' : ($state === 302 ? 'warning' : 'gray')),
                TextColumn::make('source')->badge()->color('gray'),
                TextColumn::make('hits')->numeric()->sortable(),
                TextColumn::make('last_hit_at')->label('Last used')->since()->sortable()->placeholder('Never'),
            ])
            ->filters([
                SelectFilter::make('source')->options(['manual' => 'Manual', 'wordpress' => 'WordPress', 'system' => 'System']),
                SelectFilter::make('status_code')->label('Type')->options(self::STATUS_OPTIONS),
            ])
            ->recordActions([
                EditAction::make(),
                self::deleteAction(DeleteAction::make()),
            ]);
    }

    public static function deleteAction(DeleteAction $action): DeleteAction
    {
        return $action->after(fn (Redirect $record) => Audit::record('redirect.deleted', "Deleted redirect {$record->from_path}", $record,
            changes: ['before' => $record->only('from_path', 'to_path', 'status_code')]));
    }

    /** A 410 has no destination, whatever was typed before the type changed. */
    public static function normaliseData(array $data): array
    {
        if ((int) ($data['status_code'] ?? 301) === 410) {
            $data['to_path'] = null;
        }

        return $data;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRedirects::route('/'),
            'create' => CreateRedirect::route('/create'),
            'edit' => EditRedirect::route('/{record}/edit'),
        ];
    }
}
