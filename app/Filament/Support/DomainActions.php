<?php

namespace App\Filament\Support;

use App\Domain\RuleViolation;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;

/**
 * Shared pieces for admin actions that call domain services: a re-typed password for
 * sensitive changes, and turning a refused domain rule into a notification instead of an error page.
 */
class DomainActions
{
    public static function currentPasswordField(): TextInput
    {
        return TextInput::make('current_password')
            ->label('Your password')
            ->helperText('Re-enter your own password to confirm this change.')
            ->password()
            ->revealable()
            ->required()
            ->currentPassword()
            ->dehydrated(false);
    }

    /**
     * For create/edit pages that save through a domain service: a refusal is shown and the form stays open.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function save(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (RuleViolation $e) {
            Notification::make()->danger()->title('Not saved')->body($e->getMessage())->send();

            throw new Halt;
        }
    }

    /**
     * Runs the domain call; on a rule refusal shows the reason and keeps the modal open.
     * Returns whatever the domain call returned.
     */
    public static function run(Action $action, callable $callback, string $success): mixed
    {
        try {
            $result = $callback();
        } catch (RuleViolation $e) {
            Notification::make()->danger()->title('Not done')->body($e->getMessage())->send();
            $action->halt();
        }

        Notification::make()->success()->title($success)->send();

        return $result;
    }
}
