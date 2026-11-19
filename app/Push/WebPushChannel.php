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

            return $only ? $host === $only : ! in_array($host, [Surface::Park, Surface::Logistics, Surface::Partner], true);
        });
        if ($subs->isEmpty()) {
            return Sent::skipped('нет подписок в этом приложении');
        }
        // Две строки центра уведомлений (18.11.2026, начальник: «машина в первой строке и текст во второй»): машина и что
        // случилось; без машины — заголовок и текст. Метки нет — каждое событие своим пушем (`Notice::tag`).
        $car = $notice->car();
        $text = $notice->textFor($user);
        $brief = $car ? $notice->brief($user) : null;
        $notification = array_filter([
            'title' => $car ?? $notice->titleFor($user),
            'body' => $car ? $brief.($text && $text !== $brief ? "\n".$text : '') : (string) $text,
            'tag' => $notice->tag(),
            'lang' => 'ru',
        ], fn ($v) => $v !== null);
        $href = $notice->href();
        $badges = [];
        $payloads = [];
        $push = self::client();
        foreach ($subs as $sub) {
            // Адрес — на хосте подписки: у трёх приложений три scope, чужой хост iOS открыл бы во встроенном браузере.
            $surface = $sub->host ? Surface::fromHost($sub->host) : Surface::Site;
            // Путь — под приложение подписки (NoticeLink): путь сайта в CRM — «Такой страницы нет».
            $local = NoticeLink::for(['href' => $href, 'subject' => $notice->subject()], $surface);
            $navigate = NoticeLink::tracked(str_starts_with($local, 'http') ? $local : $surface->url($local), $notice, 'push');
            $badge = $badges[$surface->value] ??= $user->badgeCount($surface);
            $payloads[$sub->id] = self::queue($push, $sub, $notification + ['navigate' => $navigate, 'app_badge' => $badge], $notice->important());
        }
        $failed = self::flush($push, $reasons, $again);
        $accepted = $subs->count() - $failed;
        // Сервер пушей не ответил (DNS, сеть, 5xx) — повтор только этим устройствам через 1, 5, 15 минут (19.11.2026):
        // принявшие второй раз пуш не получат.
        $retry = $notice->id ? array_intersect_key($payloads, array_flip($subs->whereIn('endpoint', $again)->pluck('id')->all())) : [];
        if ($retry) {
            Jobs\RetryPush::dispatch($notice->id, $user->id, ['kind' => $notice->kind(), 'label' => $notice->label(), 'category' => $notice->category(), 'important' => $notice->important()], $retry, 1)
                ->delay(now()->addSeconds(Jobs\RetryPush::DELAYS[0]));
        }

        return match (true) {
            $accepted > 0 => Sent::ok($accepted),
            (bool) $retry => Sent::retrying('повтор через минуту: '.(implode('; ', array_unique($reasons)) ?: 'не принят')),
            default => Sent::failed(implode('; ', array_unique($reasons)) ?: 'не принят', 0),
        };
    }

    /**
     * Повтор готовых пушей (`Jobs\RetryPush`): те же тексты в те же подписки, что ещё живы. Ответ — id подписок, где снова
     * временный сбой, и причины.
     *
     * @param  array<int, string>  $payloads  id подписки → готовый JSON
     * @return array{0: int, 1: list<int>, 2: list<string>} принято, снова сбой, причины
     */
    public static function resend(array $payloads): array
    {
        $subs = Subscription::whereIn('id', array_keys($payloads))->get();
        if ($subs->isEmpty()) {
            return [0, [], ['подписок нет']];
        }
        $push = self::client();
        foreach ($subs as $sub) {
            self::queueRaw($push, $sub, $payloads[$sub->id]);
        }
        $failed = self::flush($push, $reasons, $again);

        return [$subs->count() - $failed, $subs->whereIn('endpoint', $again)->pluck('id')->all(), $reasons];
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

    /** В очередь клиента; ответ — готовый JSON (для повтора). */
    private static function queue(WebPush $push, Subscription $sub, array $notification, bool $important): string
    {
        // Важное (рядом с web_push, не внутри notification — декларативный пуш Safari его не знает) sw.js держит до нажатия.
        $payload = json_encode(['web_push' => 8030, 'important' => $important, 'notification' => $notification], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        self::queueRaw($push, $sub, $payload);

        return $payload;
    }

    private static function queueRaw(WebPush $push, Subscription $sub, string $payload): void
    {
        $push->queueNotification(PushSubscription::create(['endpoint' => $sub->endpoint, 'publicKey' => $sub->p256dh, 'authToken' => $sub->auth, 'contentEncoding' => 'aes128gcm']), $payload, ['TTL' => 86400, 'urgency' => 'normal']);
    }

    /**
     * Отправить и разобрать ответы: принятым — `last_sent_at`, отвалившиеся — удалить, прочие — `failed_at`. Число неудач,
     * в `$reasons` — их причины для журнала.
     */
    private static function flush(WebPush $push, ?array &$reasons = [], ?array &$again = []): int
    {
        $reasons = [];
        $again = [];
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
                // Временное — нет ответа (DNS, сеть), 429 или 5xx: стоит повторить (`Jobs\RetryPush`), а подписка живая — `failed_at`
                // не ставим: иначе после минутного сбоя DNS настройки говорили «Пуши не включены ни на одном устройстве».
                // 400/403 — повтор не поможет, подписка сломана.
                $status = $report->getResponse()?->getStatusCode();
                Log::warning('Пуш не ушёл', ['endpoint' => substr($endpoint, 0, 60), 'reason' => $report->getReason()]);
                if ($status === null || $status === 429 || $status >= 500) {
                    $again[] = $endpoint;
                } else {
                    Subscription::where('endpoint', $endpoint)->update(['failed_at' => now()]);
                }
            }
        }
        if ($sent) {
            Subscription::whereIn('endpoint', $sent)->update(['last_sent_at' => now(), 'failed_at' => null]);
        }

        return $failed;
    }
}
