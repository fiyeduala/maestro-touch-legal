<?php

namespace App\Filament\Resources\Comments;

use App\Domain\Operations\Audit;
use App\Filament\Resources\Comments\Pages\ListComments;
use App\Models\Comment;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use UnitEnum;

/** Moderation only: staff change a comment's visibility but never its wording. */
class CommentResource extends Resource
{
    protected static ?string $model = Comment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftEllipsis;

    protected static string|UnitEnum|null $navigationGroup = 'Website';

    protected static ?int $navigationSort = 45;

    public const STATUSES = ['approved' => 'Approved', 'pending' => 'Awaiting review', 'hidden' => 'Hidden', 'spam' => 'Spam'];

    public static function getNavigationBadge(): ?string
    {
        $count = Comment::where('status', 'pending')->count();

        return $count ? (string) $count : null;
    }

    public static function table(Table $table): Table
    {
        $tz = config('app.firm_timezone');

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('post:id,title,slug'))
            ->defaultSort('posted_at', 'desc')
            ->columns([
                TextColumn::make('author_name')->label('From')->searchable(),
                // Body is stored sanitised (purifier "comment" profile), so it is safe to render.
                TextColumn::make('body')->formatStateUsing(fn (string $state) => new HtmlString($state))
                    ->html()->limit(200)->wrap()->searchable(),
                TextColumn::make('post.title')->label('On')->limit(40)
                    ->url(fn (Comment $record) => $record->post?->url(), shouldOpenInNewTab: true),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (string $state) => self::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        'approved' => 'success', 'pending' => 'warning', 'spam' => 'danger', default => 'gray',
                    }),
                TextColumn::make('posted_at')->label('Posted')->dateTime('j M Y H:i', $tz)->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(self::STATUSES),
            ])
            ->recordActions([
                ActionGroup::make([
                    self::moderate('approved', 'Approve', Heroicon::OutlinedCheck),
                    self::moderate('hidden', 'Hide', Heroicon::OutlinedEyeSlash),
                    self::moderate('spam', 'Mark as spam', Heroicon::OutlinedNoSymbol),
                    DeleteAction::make()->after(fn (Comment $record) => Audit::record('comment.deleted',
                        "Deleted comment by {$record->author_name}", $record)),
                ]),
            ]);
    }

    private static function moderate(string $status, string $label, Heroicon $icon): Action
    {
        return Action::make($status)->label($label)->icon($icon)
            ->authorize('update')
            ->visible(fn (Comment $record) => $record->status !== $status)
            ->action(function (Comment $record) use ($status) {
                $before = $record->status;
                $record->update(['status' => $status]);
                Audit::record('comment.moderated', "Comment by {$record->author_name}: {$before} → {$status}", $record,
                    changes: ['before' => ['status' => $before], 'after' => ['status' => $status]]);
            });
    }

    public static function getPages(): array
    {
        return ['index' => ListComments::route('/')];
    }
}
