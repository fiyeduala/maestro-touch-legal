<?php

namespace App\Models;

use App\Domain\Documents\DocumentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Document extends Model
{
    public const CATEGORIES = [
        'client_supplied' => 'Client-supplied document',
        'evidence' => 'Evidence',
        'correspondence' => 'Correspondence',
        'deliverable' => 'Deliverable / draft',
        'engagement' => 'Engagement / signed terms',
        'identity' => 'Identity document',
        'court_filing' => 'Filing / official document',
        'other' => 'Other',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => DocumentStatus::class,
            'is_deliverable' => 'boolean',
            'uploaded_by_client' => 'boolean',
            'released_at' => 'datetime',
            'client_decision_at' => 'datetime',
        ];
    }

    /**
     * What a client may see: documents with a released version (always at that version, even while a
     * newer internal draft is in progress) and their own uploads.
     */
    public function scopeClientVisible(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q->whereNotNull('released_version_id')->orWhere('uploaded_by_client', true));
    }

    public function isClientVisible(): bool
    {
        return $this->released_version_id !== null || $this->uploaded_by_client;
    }

    /** The version a client receives: the released one, or for their own uploads the latest. */
    public function clientVersion(): ?DocumentVersion
    {
        if ($this->released_version_id) {
            return $this->releasedVersion;
        }

        return $this->uploaded_by_client ? $this->currentVersion : null;
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function enquiry(): BelongsTo
    {
        return $this->belongsTo(Enquiry::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(DocumentRequest::class, 'document_request_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(DocumentVersion::class)->orderByDesc('version');
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'current_version_id');
    }

    public function releasedVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'released_version_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(DocumentEvent::class)->orderByDesc('created_at')->orderByDesc('id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
