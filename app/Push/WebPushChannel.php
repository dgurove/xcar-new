<?php

namespace App\Push;

use App\Notifications\Notice;
use App\Notifications\NoticeLink;
use App\Support\Surface;
use App\Users\User;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription as PushSubscription;
use Minishlink\WebPush\WebPush;

/**
 * Пуш подписчикам человека. Формат — Declarative Web Push: Safari 18.4+
 * показывает его сам, без воркера; на Android тот же JSON читает sw.js.
 * Отвалившаяся подписка (404/410) удаляется. Значок приложения — число того хоста, чья подписка.
 */
final class WebPushChannel
{
    public function send(User $user, Notice $notice): void
    {
        $subs = Subscription::where('user_id', $user->id)->get();
        if ($subs->isEmpty() || ! config('xcar.vapid.public')) {
            return;
        }
        // Парковка — отдельное приложение: её пуш только на подписки её хоста, остальное — не на них.
        $park = $notice->surface() === Surface::Park;
        $subs = $subs->filter(fn ($sub) => ($sub->host && Surface::fromHost($sub->host) === Surface::Park) === $park);
        if ($subs->isEmpty()) {
            return;
        }
        $notification = [
            'title' => $notice->title(),
            'body' => (string) $notice->text(),
            'tag' => $notice->tag(),
            'lang' => 'ru',
        ];
        $href = $notice->href();
        $badges = [];

        $push = new WebPush(['VAPID' => ['subject' => config('xcar.vapid.subject'), 'publicKey' => config('xcar.vapid.public'), 'privateKey' => config('xcar.vapid.private')]]);
        $push->setReuseVAPIDHeaders(true);
        foreach ($subs as $sub) {
            // Адрес — на хосте подписки: у трёх приложений три scope, чужой хост iOS открыл бы во встроенном браузере.
            $surface = $sub->host ? Surface::fromHost($sub->host) : Surface::Site;
            // Путь — под приложение подписки (NoticeLink): путь сайта в CRM — «Такой страницы нет».
            $local = NoticeLink::for(['href' => $href, 'subject' => $notice->subject()], $surface);
            $navigate = str_starts_with($local, 'http') ? $local : $surface->url($local);
            $badge = $badges[$surface->value] ??= $user->badgeCount($surface);
            // Важное (рядом с web_push, не внутри notification — декларативный пуш Safari его не знает) sw.js держит до нажатия.
            $payload = json_encode(['web_push' => 8030, 'important' => $notice->important(), 'notification' => $notification + ['navigate' => $navigate, 'app_badge' => $badge]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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
