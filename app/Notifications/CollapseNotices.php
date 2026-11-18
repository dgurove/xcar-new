<?php

namespace App\Notifications;

use App\Users\User;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Events\NotificationSent;

/**
 * Строка на объект (решение владельца 01.10.2026): новое уведомление о сделке, чате, предложении или счёте убирало из ленты
 * прежние о нём же. С 18.11.2026 — только у того, что заменяет само себя (`Notice::collapses`: тревога срока, сводки, чат
 * менеджера): владелец — «каждое событие отдельной строкой» (было: «Отправить счёт» стирало «Роман берёт», и начальник не видел).
 */
final class CollapseNotices
{
    public function handle(NotificationSent $e): void
    {
        if ($e->channel !== 'database' || ! $e->notifiable instanceof User || ! $e->response instanceof DatabaseNotification
            || ! $e->notification instanceof Notice || ! $e->notification->collapses($e->notifiable)) {
            return;
        }
        $subject = $e->response->data['subject'] ?? null;
        if (! $subject) {
            return;
        }
        $e->notifiable->notifications()->where('data->subject', $subject)->whereKeyNot($e->response->getKey())->delete();
    }
}
