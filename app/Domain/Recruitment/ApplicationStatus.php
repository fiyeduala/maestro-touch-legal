<?php

namespace App\Domain\Recruitment;

enum ApplicationStatus: string
{
    case Submitted = 'submitted';
    case UnderReview = 'under_review';
    case MoreInfoRequested = 'more_info_requested';
    case Approved = 'approved';
    case Declined = 'declined';
    case Withdrawn = 'withdrawn';

    public function label(): string
    {
        return match ($this) {
            self::Submitted => 'Submitted',
            self::UnderReview => 'Under review',
            self::MoreInfoRequested => 'More information requested',
            self::Approved => 'Approved',
            self::Declined => 'Declined',
            self::Withdrawn => 'Withdrawn',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Submitted => 'info',
            self::UnderReview, self::MoreInfoRequested => 'warning',
            self::Approved => 'success',
            self::Declined, self::Withdrawn => 'gray',
        };
    }

    public function isOpen(): bool
    {
        return ! in_array($this, [self::Approved, self::Declined, self::Withdrawn], true);
    }

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Submitted => [self::UnderReview, self::MoreInfoRequested, self::Approved, self::Declined, self::Withdrawn],
            self::UnderReview => [self::MoreInfoRequested, self::Approved, self::Declined, self::Withdrawn],
            self::MoreInfoRequested => [self::UnderReview, self::Approved, self::Declined, self::Withdrawn],
            default => [],
        };
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
