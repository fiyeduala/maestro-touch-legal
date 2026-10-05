<?php

namespace App\Notifications\Concerns;

use App\Domain\Operations\Settings;
use App\Models\User;
use App\Notifications\Channels\WebPushChannel;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;

/**
 * Email for everyone; the in-app bell and browser push for people with an account (D49). The bell entry
 * and the push go out straight away; only the email waits for the queue (cron, every five minutes).
 * Wording comes from inApp(), which must be as discreet as the email: a push can appear on a lock screen.
 */
trait InAppAndPush
{
    /** @return array{title: string, body: string, url: string, icon?: string} */
    abstract protected function inApp(object $notifiable): array;

    /** @return list<string> */
    protected function channels(object $notifiable, bool $mail = true): array
    {
        $account = $notifiable instanceof User;

        return array_values(array_filter([
            $mail ? 'mail' : null,
            $account ? 'database' : null,
            $account ? WebPushChannel::class : null,
        ]));
    }

    /** @return array<string, string> */
    public function viaConnections(): array
    {
        return ['database' => 'sync', WebPushChannel::class => 'sync'];
    }

    /** Filament's format, so the staff panel's bell shows it; the portal reads title, body and url. */
    public function toDatabase(object $notifiable): array
    {
        $message = $this->inApp($notifiable);
        $url = url($message['url']);

        return FilamentNotification::make()
            ->title($message['title'])
            ->body($message['body'])
            ->icon($message['icon'] ?? 'heroicon-o-bell')
            ->actions([Action::make('open')->label('Open')->url($url)->markAsRead()])
            ->getDatabaseMessage() + ['url' => $url];
    }

    /** @return array{title: string, body: string, url: string, icon: string, tag: string} */
    public function toWebPush(object $notifiable): array
    {
        $message = $this->inApp($notifiable);

        return [
            'title' => $message['title'],
            'body' => $message['body'],
            'url' => url($message['url']),
            'icon' => url((string) Settings::get('site.favicon_path')),
            'tag' => class_basename($this).'-'.md5($message['url']),
        ];
    }
}
