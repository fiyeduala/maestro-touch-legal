<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Party extends Model
{
    public const ROLES = [
        'client' => 'Client / enquirer',
        'opposing' => 'Opposing party',
        'related' => 'Related party',
        'other' => 'Other',
    ];

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::saving(function (Party $party) {
            $party->normalized_name = self::normalize($party->name);
        });
    }

    /** Lower-case ASCII words without punctuation or common company suffixes, for conflict search. */
    public static function normalize(string $name): string
    {
        $name = Str::lower(Str::ascii($name));
        $name = preg_replace('/[^a-z0-9 ]+/', ' ', $name);
        $name = preg_replace('/\b(ltd|limited|plc|llc|inc|co|company|and|the|mr|mrs|ms|dr)\b/', ' ', $name);

        return trim(preg_replace('/\s+/', ' ', $name));
    }

    public function enquiry(): BelongsTo
    {
        return $this->belongsTo(Enquiry::class);
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }
}
