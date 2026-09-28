<?php

namespace App\Domain\Documents;

enum DocumentStatus: string
{
    // Ordinary files
    case Filed = 'filed';
    // Deliverables (drafts prepared by the firm)
    case Draft = 'draft';
    case InReview = 'in_review';
    case ChangesRequested = 'changes_requested';
    case Approved = 'approved';
    // Either: visible to the client
    case Released = 'released';

    public function label(): string
    {
        return match ($this) {
            self::Filed => 'Filed',
            self::Draft => 'Draft',
            self::InReview => 'In internal review',
            self::ChangesRequested => 'Changes requested',
            self::Approved => 'Approved for release',
            self::Released => 'Released to client',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Filed => 'gray',
            self::Draft => 'gray',
            self::InReview => 'warning',
            self::ChangesRequested => 'danger',
            self::Approved => 'info',
            self::Released => 'success',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $s) => [$s->value => $s->label()])->all();
    }
}
