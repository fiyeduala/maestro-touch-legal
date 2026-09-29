<?php

namespace App\Filament\Pages;

use App\Domain\Identity\Role;
use App\Domain\Operations\Audit;
use App\Domain\Reporting\Reports as ReportData;
use App\Models\Service;
use App\Models\User;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * Finance figures (per currency, never combined) for finance officers and full administrators, and
 * practice figures for full administrators. Every CSV export is audited with its filters.
 *
 * @property-read Schema $form
 */
class Reports extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|UnitEnum|null $navigationGroup = 'Billing';

    protected static ?int $navigationSort = 90;

    protected static ?string $slug = 'reports';

    public ?array $filters = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user && $user->isActive() && ($user->isFullAdministrator() || $user->hasRole(Role::FinanceOfficer));
    }

    public function mount(): void
    {
        $tz = config('app.firm_timezone');
        $this->form->fill(['from' => now($tz)->startOfMonth()->toDateString(), 'until' => now($tz)->toDateString()]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('filters')->components([
            Grid::make(['default' => 1, 'md' => 5])->schema([
                DatePicker::make('from')->required()->live()->beforeOrEqual('until'),
                DatePicker::make('until')->required()->live(),
                Select::make('currency')->options(Money::currencyOptions())->placeholder('Both, shown separately')->live(),
                Select::make('service_id')->label('Service')->options(fn () => Service::orderBy('name')->pluck('name', 'id'))->searchable()->live(),
                Select::make('team_user_id')->label('Team member')->searchable()->live()
                    ->options(fn () => User::active()->withActiveRole(Role::Lawyer, Role::CaseOfficer)->orderBy('name')->pluck('name', 'id')),
            ]),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Filters')->schema([EmbeddedSchema::make('form')]),
            Section::make('Money')
                ->description('Each currency is reported on its own. No exchange rates are recorded, so NGN and USD are never added together.')
                ->schema([View::make('filament.reports-finance')->viewData(fn () => ['finance' => app(ReportData::class)->finance($this->validFilters())])]),
            Section::make('Practice')
                ->visible(fn () => auth()->user()->isFullAdministrator())
                ->schema([View::make('filament.reports-practice')->viewData(fn () => ['practice' => app(ReportData::class)->practice($this->validFilters())])]),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportInvoices')
                ->label('Export invoices (CSV)')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->action(fn () => $this->exportInvoices()),
        ];
    }

    public function exportInvoices(): StreamedResponse
    {
        abort_unless(static::canAccess(), 403);
        $filters = $this->validFilters();
        $rows = app(ReportData::class)->invoiceRows($filters);
        Audit::record('report.exported', 'Invoice report exported ('.count($rows).' rows)', null,
            ['after' => ['report' => 'invoices', 'filters' => array_filter($filters)]]);

        $name = 'invoices-'.($filters['from'] ?? 'start').'-to-'.($filters['until'] ?? 'today').'.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // lets Excel read ₦ and other UTF-8 correctly
            if ($rows) {
                fputcsv($out, array_keys($rows[0]));
            }
            foreach ($rows as $row) {
                // Neutralise spreadsheet formulas in text cells.
                fputcsv($out, array_map(fn ($v) => preg_match('/^[=+\-@\t\r]/', (string) $v) && ! is_numeric($v) ? "'".$v : $v, $row));
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    /** @return array{from: ?string, until: ?string, currency: ?string, service_id: ?int, team_user_id: ?int} */
    private function validFilters(): array
    {
        $f = $this->filters ?? [];
        $date = fn ($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
        $from = $date($f['from'] ?? null);
        $until = $date($f['until'] ?? null);
        if ($from && $until && $from > $until) {
            [$from, $until] = [$until, $from];
        }

        return [
            'from' => $from,
            'until' => $until,
            'currency' => array_key_exists($f['currency'] ?? '', Money::CURRENCIES) ? $f['currency'] : null,
            'service_id' => filled($f['service_id'] ?? null) ? (int) $f['service_id'] : null,
            'team_user_id' => filled($f['team_user_id'] ?? null) ? (int) $f['team_user_id'] : null,
        ];
    }
}
