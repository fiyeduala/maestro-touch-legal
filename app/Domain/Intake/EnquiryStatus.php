<?php

namespace App\Domain\Intake;

enum EnquiryStatus: string
{
    case New = 'new';
    case Triage = 'triage';
    case AwaitingInformation = 'awaiting_information';
    case ConsultationScheduled = 'consultation_scheduled';
    case Assessment = 'assessment';
    case QuoteSent = 'quote_sent';
    case EngagementPending = 'engagement_pending';
    case Converted = 'converted';
    case Declined = 'declined';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::New => 'New',
            self::Triage => 'Triage',
            self::AwaitingInformation => 'Awaiting information',
            self::ConsultationScheduled => 'Consultation scheduled',
            self::Assessment => 'Assessment',
            self::QuoteSent => 'Quote sent',
            self::EngagementPending => 'Engagement pending',
            self::Converted => 'Converted to matter',
            self::Declined => 'Declined',
            self::Closed => 'Closed',
        };
    }

    /** Wording shown to the person who submitted the enquiry. */
    public function clientLabel(): string
    {
        return match ($this) {
            self::New, self::Triage, self::Assessment => 'Being reviewed',
            self::AwaitingInformation => 'We need more information',
            self::ConsultationScheduled => 'Consultation scheduled',
            self::QuoteSent => 'Quotation ready',
            self::EngagementPending => 'Engagement terms ready',
            self::Converted => 'Matter opened',
            self::Declined, self::Closed => 'Closed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::New => 'info',
            self::Triage, self::AwaitingInformation, self::Assessment => 'warning',
            self::ConsultationScheduled, self::QuoteSent, self::EngagementPending => 'primary',
            self::Converted => 'success',
            self::Declined, self::Closed => 'gray',
        };
    }

    public function isOpen(): bool
    {
        return ! in_array($this, [self::Converted, self::Declined, self::Closed], true);
    }

    /**
     * Manual pipeline moves. Conversion happens only through engagement approval,
     * and closing/declining requires a reason (enforced by the service).
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        if ($this === self::Converted) {
            return [];
        }
        if (! $this->isOpen()) {
            return [self::Triage]; // reopen
        }

        return array_values(array_filter(self::cases(), fn (self $s) => $s !== $this && $s !== self::Converted));
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $s) => [$s->value => $s->label()])->all();
    }
}
