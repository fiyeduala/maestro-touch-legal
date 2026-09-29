<?php

namespace App\Models;

use App\Domain\Identity\Role;
use App\Domain\Matters\MatterStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Matter extends Model
{
    public const PRIORITIES = ['low' => 'Low', 'normal' => 'Normal', 'high' => 'High', 'urgent' => 'Urgent'];

    public const CONFIDENTIALITY = ['standard' => 'Standard', 'sensitive' => 'Sensitive', 'restricted' => 'Restricted'];

    /** Items that must be confirmed before closing (spec §7). Outstanding balances are checked for real in Phase 5. */
    public const CLOSURE_CHECKLIST = [
        'final_documents' => 'Final documents released or filed',
        'client_informed' => 'Client informed of the outcome and closure',
        'balances_reviewed' => 'Outstanding balances reviewed',
        'retention_reviewed' => 'Records retention and legal hold reviewed',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => MatterStatus::class,
            'next_action_due_at' => 'datetime',
            'next_action_client_visible' => 'boolean',
            'opened_at' => 'datetime',
            'representation_started_at' => 'datetime',
            'closed_at' => 'datetime',
            'closure_checklist' => 'array',
            'legal_hold' => 'boolean',
        ];
    }

    /**
     * Matters a user may link a billing record to. Finance officers bill every matter but may not
     * open them, so this only ever feeds reference/title pickers, never a matter screen.
     */
    public function scopeLinkableForBilling(Builder $query, User $user): Builder
    {
        if ($user->isActive() && $user->hasRole(Role::FinanceOfficer)) {
            return $query;
        }

        return $query->visibleTo($user);
    }

    /**
     * The single source of truth for which matters a user may see.
     * Full administrators: all. Lawyers and case officers: active team membership only.
     * Clients: matters of clients they are an active contact for. Everyone else: none.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if (! $user->isActive()) {
            return $query->whereRaw('1 = 0');
        }
        if ($user->isFullAdministrator()) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($user) {
            $matched = false;
            if ($user->hasRole(Role::Lawyer, Role::CaseOfficer)) {
                $q->orWhereHas('activeTeam', fn (Builder $t) => $t->where('user_id', $user->id));
                $matched = true;
            }
            if ($user->hasRole(Role::Client)) {
                $q->orWhereIn('client_id', $user->clients()->select('clients.id'));
                $matched = true;
            }
            if (! $matched) {
                $q->whereRaw('1 = 0');
            }
        });
    }

    public function isOnTeam(User $user): bool
    {
        return $this->activeTeam()->where('user_id', $user->id)->exists();
    }

    public function isClientContact(User $user): bool
    {
        return $user->hasRole(Role::Client) && $user->clients()->whereKey($this->client_id)->exists();
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function enquiry(): BelongsTo
    {
        return $this->belongsTo(Enquiry::class);
    }

    public function engagement(): BelongsTo
    {
        return $this->belongsTo(Engagement::class);
    }

    public function team(): HasMany
    {
        return $this->hasMany(MatterTeamMember::class)->orderBy('added_at');
    }

    public function activeTeam(): HasMany
    {
        return $this->hasMany(MatterTeamMember::class)->whereNull('removed_at');
    }

    public function responsible(): HasOne
    {
        return $this->hasOne(MatterTeamMember::class)->whereNull('removed_at')->where('role', 'responsible');
    }

    public function events(): HasMany
    {
        return $this->hasMany(MatterEvent::class)->orderByDesc('created_at')->orderByDesc('id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function notarisationHandoffs(): HasMany
    {
        return $this->hasMany(NotarisationHandoff::class)->latest('id');
    }

    public function deadlines(): HasMany
    {
        return $this->hasMany(MatterDeadline::class)->orderBy('due_at');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function documentRequests(): HasMany
    {
        return $this->hasMany(DocumentRequest::class);
    }

    public function parties(): HasMany
    {
        return $this->hasMany(Party::class);
    }

    public function quotations(): HasMany
    {
        return $this->hasMany(Quotation::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function consultations(): HasMany
    {
        return $this->hasMany(Consultation::class);
    }

    /** Digest content for this matter: full conversation text, or counts only for sensitive matters. */
    public function digestMode(): string
    {
        return $this->digest_mode ?? ($this->confidentiality === 'restricted' ? 'summary' : 'full');
    }

    /** @return array<string, string> the stages of this matter's service (or the default list) */
    public function stageOptions(): array
    {
        return $this->service?->stageOptions() ?? collect(Service::DEFAULT_STAGES)->pluck('label', 'key')->all();
    }

    public function stageLabel(): string
    {
        return $this->stageOptions()[$this->stage] ?? $this->stage;
    }

    public function isClosed(): bool
    {
        return $this->status === MatterStatus::Closed;
    }
}
