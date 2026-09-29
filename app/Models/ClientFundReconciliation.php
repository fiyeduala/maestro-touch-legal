<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientFundReconciliation extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['statement_date' => 'date', 'statement_balance_minor' => 'integer', 'ledger_balance_minor' => 'integer'];
    }

    public function differenceMinor(): int
    {
        return $this->statement_balance_minor - $this->ledger_balance_minor;
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
