<?php

namespace App\Domain\Billing;

/**
 * Stored status. "Overdue" is not stored: it is an issued or part-paid invoice whose due date has passed
 * in the firm timezone (Invoice::isOverdue).
 */
enum InvoiceStatus: string
{
    case Draft = 'draft';
    case Issued = 'issued';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Credited = 'credited';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Issued => 'Issued – unpaid',
            self::PartiallyPaid => 'Part paid',
            self::Paid => 'Paid',
            self::Credited => 'Credited in full',
            self::Cancelled => 'Cancelled (draft discarded)',
        };
    }

    public function clientLabel(): string
    {
        return match ($this) {
            self::Draft, self::Cancelled => 'Not issued',
            self::Issued => 'Unpaid',
            self::PartiallyPaid => 'Part paid',
            self::Paid => 'Paid',
            self::Credited => 'Credited',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Issued => 'warning',
            self::PartiallyPaid => 'info',
            self::Paid => 'success',
            self::Credited, self::Cancelled => 'gray',
        };
    }

    /** Issued to the client and still expecting money. */
    public function isOpen(): bool
    {
        return in_array($this, [self::Issued, self::PartiallyPaid], true);
    }

    /** Visible to the client at all. */
    public function isIssued(): bool
    {
        return ! in_array($this, [self::Draft, self::Cancelled], true);
    }

    /** @return list<string> */
    /** @return list<string> statuses of invoices that were issued to the client (counted as invoiced) */
    public static function issuedValues(): array
    {
        return collect(self::cases())->filter(fn (self $s) => $s->isIssued())->map(fn (self $s) => $s->value)->values()->all();
    }

    public static function openValues(): array
    {
        return [self::Issued->value, self::PartiallyPaid->value];
    }
}
