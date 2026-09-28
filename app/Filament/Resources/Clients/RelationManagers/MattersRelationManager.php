<?php

namespace App\Filament\Resources\Clients\RelationManagers;

use App\Filament\Resources\Matters\MatterResource;
use App\Models\Matter;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** The client's matters, limited to those the viewer may open. */
class MattersRelationManager extends RelationManager
{
    protected static string $relationship = 'matters';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('view', $ownerRecord) ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->visibleTo(auth()->user()))
            ->columns([
                TextColumn::make('reference'),
                TextColumn::make('title')->wrap(),
                MatterResource::statusBadge(TextColumn::make('status')),
                TextColumn::make('opened_at')->label('Opened')->date(),
            ])
            ->recordUrl(fn (Matter $record) => MatterResource::getUrl('view', ['record' => $record]));
    }
}
