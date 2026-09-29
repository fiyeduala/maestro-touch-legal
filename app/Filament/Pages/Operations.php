<?php

namespace App\Filament\Pages;

use App\Domain\Communication\Digests;
use App\Filament\Support\DomainActions;
use App\Models\Delivery;
use App\Models\Digest;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/**
 * Health of the background work on shared hosting: when the scheduler last ran, the database queue,
 * recent email failures and the end-of-day recaps. Full administrators only.
 * Delivery is never guaranteed exactly once; a failed or uncertain recap is resent only by hand, and audited.
 */
class Operations extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedServerStack;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 20;

    protected static ?string $title = 'Operations';

    protected static ?string $slug = 'operations';

    /** The cPanel cron runs every 5 minutes, so a heartbeat older than this means it has stopped. */
    public const STALE_AFTER_MINUTES = 15;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->isFullAdministrator();
    }

    public static function getNavigationBadge(): ?string
    {
        $count = Digest::whereIn('status', ['failed', 'uncertain'])->count();

        return $count ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Background tasks and email')->schema([View::make('filament.operations-status')->viewData(fn () => $this->status())]),
            Section::make('End-of-day recaps')
                ->description('Recaps that failed, or whose outcome is unknown, are never resent automatically. Check with the recipient before sending again, as they may already have it.')
                ->schema([EmbeddedTable::make()]),
        ]);
    }

    /** @return array<string, mixed> */
    public function status(): array
    {
        $last = Cache::get('ops.scheduler_last_run');
        $lastRun = $last ? Carbon::parse($last) : null;

        return [
            'lastRun' => $lastRun,
            'stale' => ! $lastRun || $lastRun->lt(now()->subMinutes(self::STALE_AFTER_MINUTES)),
            'pendingJobs' => DB::table('jobs')->count(),
            'failedJobs' => DB::table('failed_jobs')->count(),
            'sent24h' => Delivery::where('created_at', '>=', now()->subDay())->where('status', 'sent')->count(),
            'failed24h' => Delivery::where('created_at', '>=', now()->subDay())->where('status', 'failed')->count(),
            'recentFailures' => Delivery::where('status', 'failed')->latest('id')->limit(10)->get(),
            'mailer' => config('mail.default'),
        ];
    }

    public function table(Table $table): Table
    {
        $tz = config('app.firm_timezone');

        return $table
            ->query(fn () => Digest::query()->with('client:id,display_name'))
            ->defaultSort('id', 'desc')
            ->emptyStateHeading('No recaps yet')
            ->columns([
                TextColumn::make('window_end')->label('For the day ending')->dateTime('j M Y, H:i', $tz),
                TextColumn::make('email')->label('Recipient')->searchable()
                    ->description(fn (Digest $record) => $record->kind === 'firm' ? 'Firm recap' : $record->client?->display_name),
                TextColumn::make('message_ids')->label('Messages')->state(fn (Digest $record) => count($record->message_ids ?? [])),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (string $state) => Digest::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        'sent' => 'success', 'failed', 'uncertain' => 'danger', 'skipped' => 'gray', default => 'warning',
                    })
                    ->description(fn (Digest $record) => $record->skipped_reason ?? $record->last_error),
                TextColumn::make('sent_at')->label('Sent')->dateTime('j M H:i', $tz)->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('status')->options(Digest::STATUSES),
            ])
            ->recordActions([
                Action::make('retry')
                    ->label('Send again')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->visible(fn (Digest $record) => in_array($record->status, ['failed', 'uncertain', 'skipped'], true))
                    ->requiresConfirmation()
                    ->modalDescription('The recap is rebuilt when it is sent: access is checked again and messages the recipient can no longer see are left out. This is recorded in the audit log.')
                    ->action(fn (Action $action, Digest $record) => DomainActions::run($action,
                        fn () => app(Digests::class)->retry($record, auth()->user()), 'Queued to send with the next scheduled run')),
            ]);
    }
}
