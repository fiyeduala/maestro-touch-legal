<?php

namespace App\Filament\Resources\AuditEvents;

use App\Filament\Resources\AuditEvents\Pages\ListAuditEvents;
use App\Filament\Resources\AuditEvents\Pages\ViewAuditEvent;
use App\Models\AuditEvent;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\CodeEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use UnitEnum;

/**
 * Read-only view of the audit log. The model refuses updates and deletes, and the policy offers no
 * edit actions; this is append-only in the application, not cryptographically tamper-proof.
 */
class AuditEventResource extends Resource
{
    protected static ?string $model = AuditEvent::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 90;

    protected static ?string $navigationLabel = 'Audit log';

    protected static ?string $modelLabel = 'audit event';

    public static function infolist(Schema $schema): Schema
    {
        $tz = config('app.firm_timezone');

        return $schema->components([
            Section::make()->columns(2)->columnSpanFull()->schema([
                TextEntry::make('occurred_at')->label('When')->dateTime('j M Y H:i:s', $tz),
                TextEntry::make('action')->badge(),
                TextEntry::make('summary')->columnSpanFull(),
                TextEntry::make('actor_name')->label('By')->placeholder(fn (AuditEvent $record) => ucfirst($record->actor_type)),
                TextEntry::make('actor_roles')->label('Roles at the time')->badge()->placeholder('—'),
                TextEntry::make('subject_type')->label('Record')
                    ->formatStateUsing(fn (AuditEvent $record) => $record->subject_type ? class_basename($record->subject_type)." #{$record->subject_id}" : null)
                    ->placeholder('—'),
            ]),
            Section::make('Changes')->columnSpanFull()->visible(fn (AuditEvent $record) => filled($record->changes))->schema([
                CodeEntry::make('changes')->hiddenLabel()
                    ->state(fn (AuditEvent $record) => json_encode($record->changes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
            ]),
            Section::make('Context')->columnSpanFull()->collapsed()->schema([
                CodeEntry::make('context')->hiddenLabel()
                    ->state(fn (AuditEvent $record) => json_encode($record->context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        $tz = config('app.firm_timezone');

        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('occurred_at')->label('When')->dateTime('j M Y H:i', $tz)->sortable(),
                TextColumn::make('actor_name')->label('By')->searchable()
                    ->placeholder(fn (AuditEvent $record) => ucfirst($record->actor_type)),
                TextColumn::make('action')->badge()->color('gray')->searchable(),
                TextColumn::make('summary')->searchable()->wrap()->limit(120),
                TextColumn::make('context.ip')->label('IP')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('area')
                    ->options(fn () => AuditEvent::query()->selectRaw('distinct action')->pluck('action')
                        ->map(fn ($a) => explode('.', $a)[0])->unique()->sort()->mapWithKeys(fn ($a) => [$a => str($a)->headline()->toString()])->all())
                    ->query(fn (Builder $query, array $data) => $data['value'] ? $query->where('action', 'like', $data['value'].'.%') : $query),
                SelectFilter::make('actor_id')->label('Person')->relationship('actor', 'name')->searchable(),
                Filter::make('occurred_at')->label('Date range')
                    ->schema([
                        DatePicker::make('from'),
                        DatePicker::make('until'),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $d) => $q->where('occurred_at', '>=', Carbon::parse($d, $tz)->startOfDay()->utc()))
                        ->when($data['until'] ?? null, fn ($q, $d) => $q->where('occurred_at', '<=', Carbon::parse($d, $tz)->endOfDay()->utc()))),
            ])
            ->recordActions([ViewAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAuditEvents::route('/'),
            'view' => ViewAuditEvent::route('/{record}'),
        ];
    }
}
