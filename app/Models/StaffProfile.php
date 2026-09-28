<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffProfile extends Model
{
    protected $guarded = ['id', 'credentials_verified_at', 'credentials_verified_by'];

    protected function casts(): array
    {
        return [
            'is_affiliate' => 'boolean',
            'practice_areas' => 'array',
            'credentials_verified_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function credentialsVerifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'credentials_verified_by');
    }
}
