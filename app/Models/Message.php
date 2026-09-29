<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One entry in a matter conversation. audience "client" is the private client–firm chat;
 * "internal" is the staff-only notes area and must never reach a client view, email, digest or export.
 */
class Message extends Model
{
    public const CHANNELS = ['phone' => 'Phone call', 'whatsapp' => 'WhatsApp', 'meeting' => 'Meeting', 'other' => 'Other'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['sender_is_client' => 'boolean'];
    }

    public function scopeClientVisible(Builder $query): Builder
    {
        return $query->where('audience', 'client');
    }

    public function scopeInternal(Builder $query): Builder
    {
        return $query->where('audience', 'internal');
    }

    public function isInternal(): bool
    {
        return $this->audience === 'internal';
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function amends(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'amends_message_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function reads(): HasMany
    {
        return $this->hasMany(MessageRead::class);
    }

    /** Sender as shown in the conversation and digests: the client by name, staff as the firm. */
    public function senderLabel(): string
    {
        $name = $this->sender?->name ?? 'Former user';

        return $this->sender_is_client ? $name : "{$name} (Maestro Touch Legal)";
    }

    public function kindLabel(): ?string
    {
        return match ($this->kind) {
            'call_note' => 'Record of '.mb_strtolower(self::CHANNELS[$this->channel] ?? 'conversation'),
            'amendment' => 'Correction',
            default => null,
        };
    }
}
