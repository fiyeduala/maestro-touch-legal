<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One end-of-day email to one recipient for one window (spec §8). */
class Digest extends Model
{
    public const STATUSES = [
        'pending' => 'Waiting to send',
        'sending' => 'Sending',
        'sent' => 'Sent',
        'failed' => 'Failed',
        'skipped' => 'Not sent',
        'uncertain' => 'Outcome unknown',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'message_ids' => 'array',
            'window_start' => 'datetime',
            'window_end' => 'datetime',
            'claimed_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(DigestRun::class, 'digest_run_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
