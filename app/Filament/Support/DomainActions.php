<?php

namespace App\Filament\Support;

use App\Domain\RuleViolation;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;

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
     * Runs the domain call; on a rule refusal shows the reason and keeps the modal open.
     */
    public static function run(Action $action, callable $callback, string $success): void
    {
        try {
            $callback();
        } catch (RuleViolation $e) {
            Notification::make()->danger()->title('Not done')->body($e->getMessage())->send();
            $action->halt();
        }

        Notification::make()->success()->title($success)->send();
    }
}
