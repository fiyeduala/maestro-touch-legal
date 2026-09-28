<?php

namespace App\Filament\Support;

use Filament\Actions\Action;
use Filament\Actions\ActionGroup;

/**
 * For view pages whose header actions change the record through a domain service:
 * reloads the record (with the page's eager loads) after every action so what is shown is current.
 */
trait RefreshesRecord
{
    /**
     * Livewire restores the record on each request without its eager loads; reload it through
     * resolveRecord so the page's relations are present and the resource's access scope is re-applied.
     */
    public function hydrateRefreshesRecord(): void
    {
        $this->record = $this->resolveRecord($this->record->getKey());
    }

    /**
     * @param  list<Action|ActionGroup>  $actions
     * @return list<Action|ActionGroup>
     */
    protected function refreshingAfter(array $actions): array
    {
        $refresh = fn () => $this->record = $this->resolveRecord($this->record->getKey());

        foreach ($actions as $action) {
            foreach ($action instanceof ActionGroup ? $action->getActions() : [$action] as $inner) {
                $inner->after($refresh);
            }
        }

        return $actions;
    }
}
