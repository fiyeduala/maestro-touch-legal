<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A document the client agreed to have notarised through Naija Virtual Notary, tracked by hand (D40). */
class NotarisationHandoff extends Model
{
    public const CONSENT_METHODS = [
        'written' => 'Signed / written instruction',
        'email' => 'Email from the client',
        'portal' => 'Portal message from the client',
        'in_person' => 'In person or by phone (noted)',
    ];

    public const STATUSES = [
        'consented' => 'Consent recorded',
        'sent' => 'Handed to NVN',
        'in_progress' => 'With NVN',
        'completed' => 'Notarised',
        'cancelled' => 'Cancelled',
    ];

    /** Allowed next statuses. Completed and cancelled are final. */
    public const NEXT = [
        'consented' => ['sent', 'cancelled'],
        'sent' => ['in_progress', 'completed', 'cancelled'],
        'in_progress' => ['completed', 'cancelled'],
        'completed' => [],
        'cancelled' => [],
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['consent_recorded_at' => 'datetime', 'status_changed_at' => 'datetime'];
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function consentRecordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'consent_recorded_by');
    }

    public function isFinal(): bool
    {
        return self::NEXT[$this->status] === [];
    }
}
