<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ConsultationType extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_free' => 'boolean', 'is_public' => 'boolean', 'is_active' => 'boolean'];
    }

    public function scopeBookable(Builder $query): Builder
    {
        return $query->where('is_active', true)->where('is_public', true)->orderBy('sort')->orderBy('name');
    }

    /** "Free" or the configured fee; paid types are always labelled as such. */
    public function priceLabel(): string
    {
        if ($this->is_free || ! $this->fee_minor || ! $this->currency) {
            return 'Free';
        }

        return 'Paid: '.Money::format($this->fee_minor, $this->currency);
    }
}
