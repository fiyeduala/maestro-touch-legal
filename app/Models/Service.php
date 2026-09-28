<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Service extends Model
{
    /** Default matter stages (spec §7) when a service does not define its own. */
    public const DEFAULT_STAGES = [
        ['key' => 'opened', 'label' => 'Opened'],
        ['key' => 'awaiting_client_information', 'label' => 'Awaiting client information'],
        ['key' => 'in_progress', 'label' => 'In progress'],
        ['key' => 'awaiting_external_response', 'label' => 'Awaiting external response'],
        ['key' => 'client_review', 'label' => 'Client review'],
        ['key' => 'completed', 'label' => 'Completed'],
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_public' => 'boolean', 'is_active' => 'boolean', 'stages' => 'array'];
    }

    public function scopeOffered(Builder $query): Builder
    {
        return $query->where('is_active', true)->where('is_public', true)->orderBy('sort')->orderBy('name');
    }

    public function intakeForms(): HasMany
    {
        return $this->hasMany(IntakeForm::class)->orderByDesc('version');
    }

    public function publishedForm(): HasOne
    {
        return $this->hasOne(IntakeForm::class)->ofMany(['version' => 'max'], fn (Builder $q) => $q->whereNotNull('published_at'));
    }

    /** @return list<array{key: string, label: string}> */
    public function stageList(): array
    {
        return $this->stages ?: self::DEFAULT_STAGES;
    }

    /** @return array<string, string> */
    public function stageOptions(): array
    {
        return collect($this->stageList())->mapWithKeys(fn ($s) => [$s['key'] => $s['label']])->all();
    }
}
