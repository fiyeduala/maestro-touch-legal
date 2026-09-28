<?php

namespace App\Filament\Resources\Matters\RelationManagers;

use App\Models\Matter;
use App\Models\User;
use Filament\Resources\RelationManagers\RelationManager;
use Illuminate\Database\Eloquent\Model;

/**
 * Base for the matter sections. Visibility follows the matter itself (not the related model's
 * viewAny), and every change goes through a domain service that re-checks permissions.
 */
abstract class MatterRelationManager extends RelationManager
{
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('view', $ownerRecord) ?? false;
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    protected function matter(): Matter
    {
        /** @var Matter */
        return $this->getOwnerRecord();
    }

    protected function me(): User
    {
        return auth()->user();
    }

    protected function allowed(string $ability): bool
    {
        return $this->me()->can($ability, $this->matter());
    }

    protected function open(): bool
    {
        return ! $this->matter()->isClosed();
    }

    /** @return array<int, string> active staff who could be assigned work on this matter */
    protected function teamOptions(): array
    {
        return $this->matter()->activeTeam()->with('user')->get()
            ->mapWithKeys(fn ($m) => [$m->user_id => $m->user->name])->all();
    }
}
