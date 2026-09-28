<?php

namespace App\Filament\Concerns;

use App\Domain\Operations\Audit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Admin create/edit pages write an audit event with a before/after diff.
 * Long values (article bodies, JSON content) are summarised rather than copied into the log.
 */
trait AuditsRecordChanges
{
    protected function auditKey(): string
    {
        return Str::snake(class_basename(static::getResource()::getModel()));
    }

    protected function auditLabel(Model $record): string
    {
        return (string) (static::getResource()::getRecordTitle($record) ?? '#'.$record->getKey());
    }

    protected function handleRecordCreation(array $data): Model
    {
        $record = parent::handleRecordCreation($data);
        Audit::record($this->auditKey().'.created', 'Created '.Str::lower(Str::headline($this->auditKey())).' '.$this->auditLabel($record), $record);

        return $record;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $record->fill($data);
        $changes = self::summariseChanges(Audit::diff($record));
        $record->save();

        if ($changes['after']) {
            Audit::record($this->auditKey().'.updated', 'Updated '.Str::lower(Str::headline($this->auditKey())).' '.$this->auditLabel($record), $record, $changes);
        }

        return $record;
    }

    public static function summariseChanges(array $changes): array
    {
        foreach (['before', 'after'] as $side) {
            foreach ($changes[$side] as $key => $value) {
                if (is_array($value) || (is_string($value) && mb_strlen($value) > 300)) {
                    $changes[$side][$key] = '[long value, '.mb_strlen(is_string($value) ? $value : json_encode($value)).' chars]';
                }
            }
        }

        return $changes;
    }
}
