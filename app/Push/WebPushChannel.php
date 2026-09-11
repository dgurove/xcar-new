<?php

namespace App\Push;

use App\Notifications\Notice;
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
        $payload = json_encode([
            'web_push' => 8030,
            'notification' => [
                'title' => $notice->title(),
                'body' => (string) $notice->text(),
                'navigate' => url($notice->href()),
                'tag' => $notice->offerNumber() ? 'offer-'.$notice->offerNumber() : null,
                'app_badge' => $user->unreadCount(),
                'lang' => 'ru',
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $push = new WebPush(['VAPID' => ['subject' => config('xcar.vapid.subject'), 'publicKey' => config('xcar.vapid.public'), 'privateKey' => config('xcar.vapid.private')]]);
        $push->setReuseVAPIDHeaders(true);
        foreach ($subs as $sub) {
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
