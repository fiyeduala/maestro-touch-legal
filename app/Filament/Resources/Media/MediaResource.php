<?php

namespace App\Filament\Resources\Media;

use App\Domain\Operations\Audit;
use App\Filament\Resources\Media\Pages\CreateMedia;
use App\Filament\Resources\Media\Pages\EditMedia;
use App\Filament\Resources\Media\Pages\ListMedia;
use App\Models\Media;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;
use UnitEnum;

/**
 * Public website images and PDFs only. Anything uploaded here is publicly reachable,
 * so confidential client or matter documents must never be put in the media library.
 */
class MediaResource extends Resource
{
    protected static ?string $model = Media::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhoto;

    protected static string|UnitEnum|null $navigationGroup = 'Website';

    protected static ?int $navigationSort = 50;

    protected static ?string $navigationLabel = 'Media library';

    protected static ?string $modelLabel = 'media file';

    protected static ?string $recordTitleAttribute = 'original_name';

    /** SVG is deliberately excluded: it can carry script. */
    public const ACCEPTED_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'application/pdf'];

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columnSpanFull()->schema([
                Text::make('Files here are public on the website. Never upload client or matter documents.')
                    ->color('warning'),
                FileUpload::make('path')->label('File')
                    ->disk('media')->directory(fn () => now()->format('Y/m'))->visibility('public')
                    ->acceptedFileTypes(self::ACCEPTED_TYPES)->maxSize(10240)
                    ->storeFileNamesIn('original_name')
                    ->required()
                    ->visibleOn('create'),
                Text::make(fn (?Media $record) => $record ? "{$record->original_name} · {$record->mime_type} · ".Number::fileSize($record->size).($record->width ? " · {$record->width}×{$record->height}" : '') : '')
                    ->visibleOn('edit'),
                TextInput::make('alt_text')->label('Image description (alt text)')->maxLength(500)
                    ->helperText('Describe the image for people using screen readers.'),
                Textarea::make('caption')->rows(2)->maxLength(1000),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                ImageColumn::make('preview')->label('')
                    ->state(fn (Media $record) => $record->isImage() ? $record->url() : null)
                    ->imageSize(48),
                TextColumn::make('original_name')->label('Name')->searchable()->limit(50)
                    ->description(fn (Media $record) => parse_url($record->url(), PHP_URL_PATH)),
                TextColumn::make('mime_type')->label('Type')->badge()->color('gray'),
                TextColumn::make('size')->formatStateUsing(fn (int $state) => Number::fileSize($state))->sortable(),
                TextColumn::make('alt_text')->label('Alt text')->limit(40)->placeholder('Missing')->toggleable(),
                TextColumn::make('disk')->label('Source')->badge()
                    ->formatStateUsing(fn (string $state) => $state === 'legacy' ? 'WordPress' : 'Uploaded'),
                TextColumn::make('created_at')->label('Added')->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('kind')->options(['image' => 'Images', 'pdf' => 'PDFs'])
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'image' => $query->where('mime_type', 'like', 'image/%'),
                        'pdf' => $query->where('mime_type', 'application/pdf'),
                        default => $query,
                    }),
                SelectFilter::make('disk')->label('Source')->options(['legacy' => 'WordPress', 'media' => 'Uploaded']),
            ])
            ->recordActions([
                EditAction::make(),
                self::deleteAction(DeleteAction::make()),
            ]);
    }

    public static function deleteAction(DeleteAction $action): DeleteAction
    {
        return $action
            ->modalDescription('Any page or post that shows this file will show a broken image or link. This cannot be undone.')
            ->after(function (Media $record) {
                Storage::disk($record->disk)->delete($record->path);
                Audit::record('media.deleted', "Deleted media {$record->original_name}", $record,
                    changes: ['before' => $record->only('path', 'original_name', 'sha256')]);
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMedia::route('/'),
            'create' => CreateMedia::route('/create'),
            'edit' => EditMedia::route('/{record}/edit'),
        ];
    }
}
