<?php

namespace App\Domain\Engagement;

/** Shared life cycle of quotations and engagement terms sent to a client. */
enum OfferStatus: string
{
    case Draft = 'draft';
    case Sent = 'sent';
    case Accepted = 'accepted';
    case Declined = 'declined';
    case Withdrawn = 'withdrawn';
    // Engagements only: the firm's internal approval after client acceptance. Opens the matter.
    case Approved = 'approved';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Sent => 'Sent to client',
            self::Accepted => 'Accepted by client',
            self::Declined => 'Declined by client',
            self::Withdrawn => 'Withdrawn',
            self::Approved => 'Approved – matter opened',
        };
    }

    public function clientLabel(): string
    {
        return match ($this) {
            self::Draft => 'In preparation',
            self::Sent => 'Awaiting your response',
            self::Accepted => 'Accepted',
            self::Declined => 'Declined',
            self::Withdrawn => 'Withdrawn',
            self::Approved => 'Accepted – matter opened',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Sent => 'warning',
            self::Accepted => 'info',
            self::Approved => 'success',
            self::Declined, self::Withdrawn => 'danger',
        };
    }

    public function isEditable(): bool
    {
        return in_array($this, [self::Draft, self::Sent], true);
    }
}
