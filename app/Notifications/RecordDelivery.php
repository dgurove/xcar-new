<?php

namespace App\Notifications;

use App\Live\BannerChannel;
use App\Push\WebPushChannel;
use App\Users\User;
use Illuminate\Notifications\Events\NotificationSent;

/**
 * Каждый канал уведомления — строкой журнала (`Delivery`). Laravel зовёт это после канала с его ответом: пуш и Telegram
 * отвечают итогом `Sent`, лента, почта и карточка — своим (дошло до канала — `sent`).
 */
final class RecordDelivery
{
    private const CHANNELS = [
        'database' => 'feed',
        'mail' => 'mail',
        BannerChannel::class => 'banner',
        WebPushChannel::class => 'push',
        TelegramChannel::class => 'telegram',
        SystemMailChannel::class => 'mail',
    ];

    public function handle(NotificationSent $e): void
    {
        $channel = self::CHANNELS[$e->channel] ?? null;
        if (! $channel || ! $e->notifiable instanceof User || ! $e->notification instanceof Notice || ! $e->notification->id) {
            return;
        }
        $notice = $e->notification;
        $sent = $e->response instanceof Sent ? $e->response : Sent::ok();
        Delivery::log($notice, $notice->id, $e->notifiable->id, $channel, $sent->status, $sent->detail, $sent->devices);
    }
}
