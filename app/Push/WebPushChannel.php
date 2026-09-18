<?php

namespace App\Push;

use App\Notifications\Notice;
use App\Support\Surface;
use App\Users\User;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription as PushSubscription;
use Minishlink\WebPush\WebPush;

/**
 * Пуш подписчикам человека. Формат — Declarative Web Push: Safari 18.4+
 * показывает его сам, без воркера; на Android тот же JSON читает sw.js.
 * Отвалившаяся подписка (404/410) удаляется.
 */
final class WebPushChannel
{
    public function send(User $user, Notice $notice): void
    {
        $subs = Subscription::where('user_id', $user->id)->get();
        if ($subs->isEmpty() || ! config('xcar.vapid.public')) {
            return;
        }
        $notification = [
            'title' => $notice->title(),
            'body' => (string) $notice->text(),
            'tag' => $notice->tag(),
            'app_badge' => $user->badgeCount(),
            'lang' => 'ru',
        ];
        $href = $notice->href();

        $push = new WebPush(['VAPID' => ['subject' => config('xcar.vapid.subject'), 'publicKey' => config('xcar.vapid.public'), 'privateKey' => config('xcar.vapid.private')]]);
        $push->setReuseVAPIDHeaders(true);
        foreach ($subs as $sub) {
            // Адрес — на хосте подписки: у трёх приложений три scope, чужой хост iOS открыл бы во встроенном браузере.
            $surface = $sub->host ? Surface::fromHost($sub->host) : Surface::Site;
            $navigate = str_starts_with($href, 'http') ? $href : $surface->url($href);
            $payload = json_encode(['web_push' => 8030, 'notification' => $notification + ['navigate' => $navigate]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $push->queueNotification(PushSubscription::create(['endpoint' => $sub->endpoint, 'publicKey' => $sub->p256dh, 'authToken' => $sub->auth, 'contentEncoding' => 'aes128gcm']), $payload, ['TTL' => 86400, 'urgency' => 'normal']);
        }
        foreach ($push->flush() as $report) {
            if ($report->isSuccess()) {
                continue;
            }
            $endpoint = (string) $report->getRequest()->getUri();
            if ($report->isSubscriptionExpired()) {
                Subscription::where('endpoint', $endpoint)->delete();
            } else {
                Log::warning('Пуш не ушёл', ['endpoint' => substr($endpoint, 0, 60), 'reason' => $report->getReason()]);
                Subscription::where('endpoint', $endpoint)->update(['failed_at' => now()]);
            }
        }
    }
}
