<?php

namespace App\Filament\Resources\Redirects\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class RedirectForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('from_path')
                    ->required(),
                TextInput::make('to_path')
                    ->default(null),
                TextInput::make('status_code')
                    ->required()
                    ->numeric()
                    ->default(301),
                TextInput::make('source')
                    ->required()
                    ->default('manual'),
                TextInput::make('hits')
                    ->required()
                    ->numeric()
                    ->default(0),
                DateTimePicker::make('last_hit_at'),
                TextInput::make('created_by')
                    ->numeric()
                    ->default(null),
            ]);
    }
}
