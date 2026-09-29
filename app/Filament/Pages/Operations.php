<?php

namespace App\Filament\Pages;

use App\Domain\Billing\PaymentStatus;
use App\Domain\Billing\PaystackGateway;
use App\Domain\Communication\Digests;
use App\Domain\Identity\Role;
use App\Domain\Operations\Backups;
use App\Filament\Support\DomainActions;
use App\Models\Delivery;
use App\Models\Digest;
use App\Models\Payment;
use App\Models\PaymentEvent;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Forms\Components\Select;
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
use Illuminate\Support\Facades\URL;
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
            Section::make('Paystack')->description('Online payments count only after the server verifies them with Paystack. Pending checkouts are re-checked every 15 minutes.')
                ->schema([View::make('filament.operations-paystack')->viewData(fn () => $this->paystack())]),
            Section::make('Backups')->description('Encrypted nightly backups of the database and stored files. Restoring is done from the cPanel terminal (docs/BACKUP-AND-RESTORE.md).')
                ->headerActions([$this->downloadBackupAction()])
                ->schema([View::make('filament.operations-backups')->viewData(fn () => $this->backups())]),
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

    /** @return array<string, mixed> never includes keys; only the mode derived from the key prefix */
    public function paystack(): array
    {
        $gateway = app(PaystackGateway::class);
        $lastWebhook = PaymentEvent::where('provider', 'paystack')->where('source', 'webhook')->where('signature_valid', true)->max('created_at');
        $lastReconcile = Cache::get('ops.paystack_last_reconcile');

        return [
            'mode' => $gateway->mode(),
            'liveRefused' => $gateway->liveKeyRefused(),
            'currencies' => $gateway->configured() ? $gateway->currencies() : [],
            'lastWebhook' => $lastWebhook ? Carbon::parse($lastWebhook) : null,
            'lastReconcile' => $lastReconcile ? Carbon::parse($lastReconcile) : null,
            'pending' => Payment::where('method', 'paystack')->where('status', PaymentStatus::Pending->value)->count(),
            'needsReview' => Payment::where('status', PaymentStatus::NeedsReview->value)->count(),
            'webhookUrl' => route('webhooks.paystack'),
        ];
    }

    /** @return array<string, mixed> */
    public function backups(): array
    {
        $backups = app(Backups::class);
        $last = $backups->lastRun();
        $list = $backups->list();
        $free = @disk_free_space($backups->directory()) ?: @disk_free_space(storage_path());
        $total = @disk_total_space(storage_path());
        $stale = $last && ($last['ok'] ?? false) && Carbon::parse($last['at'])->lt(now()->subHours(26));

        return [
            'configured' => $backups->configured(),
            'last' => $last,
            'stale' => $stale,
            'problem' => ! $backups->configured() || ! $last || ! ($last['ok'] ?? false) || $stale,
            'count' => count($list),
            'backupSize' => $backups->human(array_sum(array_column($list, 'size'))),
            'keep' => max(1, (int) config('backup.keep')),
            'free' => $free !== false && $free !== null ? $backups->human((int) $free) : null,
            'total' => $total ? $backups->human((int) $total) : null,
            'lowDisk' => $free !== false && $free !== null && $free < 1024 ** 3,
        ];
    }

    /**
     * Backups hold every confidential file, so only a technical administrator may download one, after
     * re-entering their password. The link it opens works for five minutes, for that user only, and is audited.
     */
    public function downloadBackupAction(): Action
    {
        return Action::make('downloadBackup')
            ->label('Download a backup')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('gray')
            ->visible(fn () => auth()->user()?->isActive() && auth()->user()->hasRole(Role::TechnicalAdministrator) && app(Backups::class)->list() !== [])
            ->modalDescription('The file is encrypted with the backup password, which is not shown here. Keep the download somewhere safe and off this server.')
            ->schema([
                Select::make('name')->label('Backup')->required()
                    ->options(fn () => collect(app(Backups::class)->list())->mapWithKeys(fn ($b) => [$b['name'] => $b['name'].' ('.app(Backups::class)->human($b['size']).')']))
                    ->default(fn () => app(Backups::class)->list()[0]['name'] ?? null),
                DomainActions::currentPasswordField()->helperText('Re-enter your own password to confirm this download.'),
            ])
            ->modalSubmitActionLabel('Download')
            ->action(function (array $data) {
                abort_unless(auth()->user()->isActive() && auth()->user()->hasRole(Role::TechnicalAdministrator), 403);
                abort_unless(app(Backups::class)->find((string) $data['name']), 404);

                $this->redirect(URL::temporarySignedRoute('admin.backup-download', now()->addMinutes(5), ['name' => $data['name'], 'user' => auth()->id()]));
            });
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
