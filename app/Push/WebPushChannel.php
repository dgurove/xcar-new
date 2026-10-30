<?php

namespace App\Push;

use App\Notifications\Notice;
use App\Notifications\NoticeLink;
use App\Notifications\Sent;
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
    public function send(User $user, Notice $notice): Sent
    {
        $subs = Subscription::where('user_id', $user->id)->get();
        if ($subs->isEmpty() || ! config('xcar.vapid.public')) {
            return Sent::skipped('нет подписок');
        }
        // Пуш — в приложение уведомления: парковки, логистики и CRM — только на подписки своего хоста, общее — на сайт и CRM.
        $only = $notice->surface();
        $subs = $subs->filter(function ($sub) use ($only) {
            $host = $sub->host ? Surface::fromHost($sub->host) : Surface::Site;

            return $only ? $host === $only : ! in_array($host, [Surface::Park, Surface::Logistics], true);
        });
        if ($subs->isEmpty()) {
            return Sent::skipped('нет подписок в этом приложении');
        }
        $notification = [
            'title' => $notice->titleFor($user),
            'body' => (string) $notice->textFor($user),
            'tag' => $notice->tag(),
            'lang' => 'ru',
        ];
        $href = $notice->href();
        $badges = [];
        $push = self::client();
        foreach ($subs as $sub) {
            // Адрес — на хосте подписки: у трёх приложений три scope, чужой хост iOS открыл бы во встроенном браузере.
            $surface = $sub->host ? Surface::fromHost($sub->host) : Surface::Site;
            // Путь — под приложение подписки (NoticeLink): путь сайта в CRM — «Такой страницы нет».
            $local = NoticeLink::for(['href' => $href, 'subject' => $notice->subject()], $surface);
            $navigate = NoticeLink::tracked(str_starts_with($local, 'http') ? $local : $surface->url($local), $notice, 'push');
            $badge = $badges[$surface->value] ??= $user->badgeCount($surface);
            self::queue($push, $sub, $notification + ['navigate' => $navigate, 'app_badge' => $badge], $notice->important());
        }
        $failed = self::flush($push, $reasons);
        $accepted = $subs->count() - $failed;

        return $accepted > 0 ? Sent::ok($accepted) : Sent::failed(implode('; ', array_unique($reasons)) ?: 'не принят', 0);
    }

    /**
     * Пробный пуш ровно в эту подписку, мимо фильтра хоста (20.10.2026: «Проверить» из настроек слало `TestNotice` только
     * подпискам сайта и CRM, логистику и парковку не проверяло). Важный — на ПК висит до нажатия. Ушёл ли — ответ.
     */
    public static function test(Subscription $sub): bool
    {
        $surface = $sub->host ? Surface::fromHost($sub->host) : Surface::Site;
        $push = self::client();
        self::queue($push, $sub, ['title' => 'Уведомления работают', 'body' => 'Так будут приходить новые задачи', 'tag' => 'push-test',
            'lang' => 'ru', 'navigate' => $surface->url('/')], true);

        return self::flush($push) === 0;
    }

    private static function client(): WebPush
    {
        $push = new WebPush(['VAPID' => ['subject' => config('xcar.vapid.subject'), 'publicKey' => config('xcar.vapid.public'), 'privateKey' => config('xcar.vapid.private')]]);
        $push->setReuseVAPIDHeaders(true);

        return $push;
    }

    private static function queue(WebPush $push, Subscription $sub, array $notification, bool $important): void
    {
        // Важное (рядом с web_push, не внутри notification — декларативный пуш Safari его не знает) sw.js держит до нажатия.
        $payload = json_encode(['web_push' => 8030, 'important' => $important, 'notification' => $notification], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $push->queueNotification(PushSubscription::create(['endpoint' => $sub->endpoint, 'publicKey' => $sub->p256dh, 'authToken' => $sub->auth, 'contentEncoding' => 'aes128gcm']), $payload, ['TTL' => 86400, 'urgency' => 'normal']);
    }

    /**
     * Отправить и разобрать ответы: принятым — `last_sent_at`, отвалившиеся — удалить, прочие — `failed_at`. Число неудач,
     * в `$reasons` — их причины для журнала.
     */
    private static function flush(WebPush $push, ?array &$reasons = []): int
    {
        $reasons = [];
        $failed = 0;
        $sent = [];
        foreach ($push->flush() as $report) {
            $endpoint = (string) $report->getRequest()->getUri();
            if ($report->isSuccess()) {
                $sent[] = $endpoint;

                continue;
            }
            $failed++;
            $reasons[] = $report->isSubscriptionExpired() ? 'подписка отвалилась' : mb_substr((string) $report->getReason(), 0, 60);
            if ($report->isSubscriptionExpired()) {
                Subscription::where('endpoint', $endpoint)->delete();
            } else {
                Log::warning('Пуш не ушёл', ['endpoint' => substr($endpoint, 0, 60), 'reason' => $report->getReason()]);
                Subscription::where('endpoint', $endpoint)->update(['failed_at' => now()]);
            }
        }
        if ($sent) {
            Subscription::whereIn('endpoint', $sent)->update(['last_sent_at' => now(), 'failed_at' => null]);
        }

        return $failed;
    }
}
