<?php

namespace App\Filament\Resources\Redirects\Pages;

use App\Filament\Concerns\AuditsRecordChanges;
use App\Filament\Resources\Redirects\RedirectResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditRedirect extends EditRecord
{
    use AuditsRecordChanges;

    protected static string $resource = RedirectResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return RedirectResource::normaliseData($data);
    }

    protected function getHeaderActions(): array
    {
        return [
            RedirectResource::deleteAction(DeleteAction::make()),
        ];
    }
}
