<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StaffApplicationEvent extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'is_internal' => 'boolean'];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(StaffApplication::class, 'staff_application_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(StaffApplicationFile::class);
    }
}
