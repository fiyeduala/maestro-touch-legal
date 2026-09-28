<?php

namespace App\Domain\Matters;

enum TaskStatus: string
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case Done = 'done';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::InProgress => 'In progress',
            self::Done => 'Done',
            self::Cancelled => 'Cancelled',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Open => 'gray',
            self::InProgress => 'info',
            self::Done => 'success',
            self::Cancelled => 'danger',
        };
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::Open, self::InProgress], true);
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $s) => [$s->value => $s->label()])->all();
    }
}
