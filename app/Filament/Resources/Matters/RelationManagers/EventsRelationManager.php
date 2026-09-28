<?php

namespace App\Filament\Resources\Matters\RelationManagers;

use App\Models\MatterEvent;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** The matter timeline. Read-only; entries marked "client sees" also appear in the portal. */
class EventsRelationManager extends MatterRelationManager
{
    protected static string $relationship = 'events';

    protected static ?string $title = 'Timeline';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        $tz = config('app.firm_timezone');

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('actor'))
            ->columns([
                TextColumn::make('created_at')->label('When')->dateTime('j M Y H:i', $tz),
                TextColumn::make('summary')->wrap(),
                TextColumn::make('actor.name')->label('By')->placeholder('System'),
                IconColumn::make('client_visible')->label('Client sees')->boolean(),
            ])
            ->filters([
                TernaryFilter::make('client_visible')->label('Visible to client'),
            ])
            ->recordUrl(null)
            ->paginated([25, 50, 100]);
    }
}
