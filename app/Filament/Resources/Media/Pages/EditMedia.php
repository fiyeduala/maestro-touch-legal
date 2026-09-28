<?php

namespace App\Filament\Resources\Media\Pages;

use App\Filament\Concerns\AuditsRecordChanges;
use App\Filament\Resources\Media\MediaResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

class EditMedia extends EditRecord
{
    use AuditsRecordChanges;

    protected static string $resource = MediaResource::class;

    /** Only descriptive fields change; the file itself is fixed once uploaded. */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return array_intersect_key($data, array_flip(['alt_text', 'caption']));
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('open')->label('Open file')->icon(Heroicon::OutlinedArrowTopRightOnSquare)->color('gray')
                ->url(fn () => $this->getRecord()->url(), shouldOpenInNewTab: true),
            MediaResource::deleteAction(DeleteAction::make()),
        ];
    }
}
