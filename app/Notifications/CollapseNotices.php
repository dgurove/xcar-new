<?php

namespace App\Notifications;

use App\Users\User;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Events\NotificationSent;

/**
 * Строка на объект (решение владельца 01.10.2026): новое уведомление о сделке, чате, предложении или счёте убирает из ленты
 * прежние о нём же — по сделке было 16 строк, и в ленту переставали заходить. История остаётся на странице объекта.
 */
final class CollapseNotices
{
    public function handle(NotificationSent $e): void
    {
        if ($e->channel !== 'database' || ! $e->notifiable instanceof User || ! $e->response instanceof DatabaseNotification) {
            return;
        }
        $subject = $e->response->data['subject'] ?? null;
        if (! $subject) {
            return;
        }
        $e->notifiable->notifications()->where('data->subject', $subject)->whereKeyNot($e->response->getKey())->delete();
    }
}
