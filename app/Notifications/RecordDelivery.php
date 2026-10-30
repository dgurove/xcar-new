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
        MailboxChannel::class => 'mail',
    ];

    public function handle(NotificationSent $e): void
    {
        $channel = self::CHANNELS[$e->channel] ?? null;
        if (! $channel || ! $e->notifiable instanceof User || ! $e->notification instanceof Notice || ! $e->notification->id) {
            return;
        }
        $notice = $e->notification;
        $sent = $e->response instanceof Sent ? $e->response : Sent::ok();
        Delivery::create([
            'notice_id' => $notice->id, 'user_id' => $e->notifiable->id, 'kind' => $notice->kind(), 'label' => mb_substr($notice->label(), 0, 80),
            'category' => $notice->category(), 'important' => $notice->important(), 'channel' => $channel,
            'status' => $sent->status, 'detail' => $sent->detail, 'devices' => $sent->devices, 'created_at' => now(),
        ]);
    }
}
