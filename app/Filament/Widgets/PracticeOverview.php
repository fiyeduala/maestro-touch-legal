<?php

namespace App\Filament\Widgets;

use App\Domain\Billing\PaymentStatus;
use App\Domain\Communication\Conversations;
use App\Domain\Documents\DocumentStatus;
use App\Domain\Intake\EnquiryStatus;
use App\Domain\Matters\MatterStatus;
use App\Domain\Recruitment\ApplicationStatus;
use App\Domain\Reporting\Reports;
use App\Filament\Pages\Reports as ReportsPage;
use App\Filament\Resources\Consultations\ConsultationResource;
use App\Filament\Resources\Enquiries\EnquiryResource;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\Matters\MatterResource;
use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Resources\StaffApplications\StaffApplicationResource;
use App\Models\Consultation;
use App\Models\Document;
use App\Models\Enquiry;
use App\Models\Matter;
use App\Models\Payment;
use App\Models\StaffApplication;
use App\Models\Task;
use App\Support\Money;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** The full administrator's overview. Every figure is a live count; nothing is estimated. */
class PracticeOverview extends StatsOverviewWidget
{
    protected static ?int $sort = -1;

    protected ?string $pollingInterval = null;

    public static function canView(): bool
    {
        return (bool) auth()->user()?->isFullAdministrator();
    }

    protected function getStats(): array
    {
        $user = auth()->user();
        $tz = config('app.firm_timezone');
        $finance = app(Reports::class)->finance(['from' => now($tz)->startOfMonth()->toDateString(), 'until' => now($tz)->toDateString()]);
        $unread = collect(app(Conversations::class)->staffUnread($user))->sum(fn (array $c) => $c['client'] + $c['internal']);

        $stats = [
            Stat::make('New enquiries', Enquiry::where('status', EnquiryStatus::New->value)->count())
                ->description(Enquiry::whereNotIn('status', [EnquiryStatus::Converted->value, EnquiryStatus::Declined->value, EnquiryStatus::Closed->value])->count().' open in total')
                ->url(EnquiryResource::getUrl()),
            Stat::make('Active matters', Matter::whereIn('status', [MatterStatus::Open->value, MatterStatus::OnHold->value])->count())
                ->description(Task::query()->overdue()->count().' overdue tasks')
                ->color(Task::query()->overdue()->exists() ? 'danger' : null)
                ->url(MatterResource::getUrl()),
            Stat::make('Unread messages', $unread)->description('Client and internal, on matters you can open'),
            Stat::make('Documents in review', Document::where('status', DocumentStatus::InReview->value)->count())
                ->description('Waiting for internal approval'),
            Stat::make('Consultations, next 7 days', Consultation::query()->active()->whereBetween('starts_at', [now(), now()->addDays(7)])->count())
                ->url(ConsultationResource::getUrl()),
            Stat::make('Staff applications', StaffApplication::whereIn('status', [ApplicationStatus::Submitted->value, ApplicationStatus::UnderReview->value])->count())
                ->description('Submitted or under review')
                ->url(StaffApplicationResource::getUrl()),
        ];

        foreach ($finance as $currency => $row) {
            $stats[] = Stat::make("Unpaid {$currency} invoices", Money::format($row['outstanding'], $currency))
                ->description($row['outstanding_count'].' invoices · '.Money::format($row['overdue'], $currency).' overdue')
                ->color($row['overdue'] > 0 ? 'danger' : null)
                ->url(InvoiceResource::getUrl());
            $stats[] = Stat::make("{$currency} received this month", Money::format($row['received'], $currency))
                ->description($row['received_count'].' payments, verified only')
                ->url(ReportsPage::getUrl());
        }

        $awaiting = Payment::whereIn('status', [PaymentStatus::PendingVerification->value, PaymentStatus::NeedsReview->value])->count();
        $stats[] = Stat::make('Payments to check', $awaiting)
            ->description('Transfer slips and mismatched payments')
            ->color($awaiting ? 'warning' : null)
            ->url(PaymentResource::getUrl());

        return $stats;
    }
}
