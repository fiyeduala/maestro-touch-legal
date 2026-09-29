<?php

namespace App\Filament\Pages;

use App\Domain\Communication\Conversations as ConversationService;
use App\Filament\Resources\Matters\MatterResource;
use App\Models\Matter;
use App\Models\Message;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** Matter conversations the signed-in staff member can open, most recent activity first, with unread counts. */
class Conversations extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static string|UnitEnum|null $navigationGroup = 'Practice';

    protected static ?int $navigationSort = 2;

    protected static ?string $title = 'Messages';

    protected static ?string $slug = 'messages';

    /** @var array<int, array{client: int, internal: int}>|null */
    private ?array $unread = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user && $user->isActive() && $user->isStaff() && $user->can('viewAny', Matter::class);
    }

    public static function getNavigationBadge(): ?string
    {
        $user = auth()->user();
        $count = $user ? collect(app(ConversationService::class)->staffUnread($user))->sum(fn ($c) => $c['client'] + $c['internal']) : 0;

        return $count ? (string) $count : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Unread messages';
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    private function unread(): array
    {
        return $this->unread ??= app(ConversationService::class)->staffUnread(auth()->user());
    }

    public function table(Table $table): Table
    {
        $tz = config('app.firm_timezone');

        return $table
            ->query(fn () => Matter::query()->visibleTo(auth()->user())
                ->whereHas('messages')
                ->with('client:id,display_name,reference')
                ->addSelect(['last_message_at' => Message::select('created_at')->whereColumn('matter_id', 'matters.id')->latest('id')->limit(1)]))
            ->defaultSort('last_message_at', 'desc')
            ->poll('60s')
            ->emptyStateHeading('No conversations yet')
            ->columns([
                TextColumn::make('reference')->searchable()
                    ->description(fn (Matter $record) => $record->client->display_name),
                TextColumn::make('title')->wrap()->searchable(),
                TextColumn::make('client_unread')->label('Client chat')
                    ->state(fn (Matter $record) => $this->unread()[$record->id]['client'] ?? 0)
                    ->formatStateUsing(fn (int $state) => $state ? "{$state} unread" : '—')
                    ->badge()->color(fn (int $state) => $state ? 'primary' : 'gray'),
                TextColumn::make('internal_unread')->label('Internal notes')
                    ->state(fn (Matter $record) => $this->unread()[$record->id]['internal'] ?? 0)
                    ->formatStateUsing(fn (int $state) => $state ? "{$state} unread" : '—')
                    ->badge()->color(fn (int $state) => $state ? 'warning' : 'gray'),
                TextColumn::make('last_message_at')->label('Last message')->dateTime('j M Y, H:i', $tz)->sortable(),
            ])
            ->filters([
                TernaryFilter::make('unread')->label('Unread only')
                    ->queries(
                        true: fn (Builder $query) => $query->whereIn('matters.id', array_keys(array_filter($this->unread(), fn ($c) => $c['client'] + $c['internal'] > 0)) ?: [0]),
                        false: fn (Builder $query) => $query,
                        blank: fn (Builder $query) => $query,
                    ),
            ])
            ->recordUrl(fn (Matter $record) => MatterResource::getUrl('conversation', ['record' => $record]));
    }
}
