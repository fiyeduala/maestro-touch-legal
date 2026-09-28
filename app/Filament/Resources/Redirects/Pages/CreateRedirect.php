<?php

namespace App\Filament\Resources\Redirects\Pages;

use App\Filament\Concerns\AuditsRecordChanges;
use App\Filament\Resources\Redirects\RedirectResource;
use Filament\Resources\Pages\CreateRecord;

class CreateRedirect extends CreateRecord
{
    use AuditsRecordChanges;

    protected static string $resource = RedirectResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return RedirectResource::normaliseData($data) + ['source' => 'manual', 'created_by' => auth()->id()];
    }
}
