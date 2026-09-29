<?php

namespace App\Domain\Billing;

enum PaymentStatus: string
{
    case Pending = 'pending'; // online checkout started; nothing confirmed
    case PendingVerification = 'pending_verification'; // client says they transferred; finance must check the bank
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Abandoned = 'abandoned';
    case Rejected = 'rejected'; // transfer claim not found in the bank account
    case NeedsReview = 'needs_review'; // money may have arrived but something did not match
    case Reversed = 'reversed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Awaiting online payment',
            self::PendingVerification => 'Transfer awaiting verification',
            self::Succeeded => 'Received',
            self::Failed => 'Failed',
            self::Abandoned => 'Abandoned',
            self::Rejected => 'Rejected',
            self::NeedsReview => 'Needs review',
            self::Reversed => 'Reversed',
        };
    }

    public function clientLabel(): string
    {
        return match ($this) {
            self::Pending => 'Not completed',
            self::PendingVerification => 'Being checked by our finance team',
            self::Succeeded => 'Received',
            self::Failed, self::Abandoned => 'Not completed',
            self::Rejected => 'Not verified',
            self::NeedsReview => 'Being checked by our finance team',
            self::Reversed => 'Reversed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Succeeded => 'success',
            self::Pending, self::PendingVerification => 'warning',
            self::NeedsReview => 'danger',
            self::Failed, self::Abandoned, self::Rejected, self::Reversed => 'gray',
        };
    }

    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $s) => [$s->value => $s->label()])->all();
    }
}
