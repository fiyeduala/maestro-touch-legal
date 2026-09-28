<?php

namespace App\Domain\Matters;

enum MatterStatus: string
{
    case Open = 'open';
    case OnHold = 'on_hold';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::OnHold => 'On hold',
            self::Closed => 'Closed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Open => 'success',
            self::OnHold => 'warning',
            self::Closed => 'gray',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $s) => [$s->value => $s->label()])->all();
    }
}
