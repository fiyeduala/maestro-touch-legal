<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A staff member's hourly rate in one currency, for every matter or for one matter (D53). */
class BillingRate extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['hourly_minor' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }
}
