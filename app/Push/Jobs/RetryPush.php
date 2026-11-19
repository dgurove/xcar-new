<?php

namespace App\Push\Jobs;

use App\Notifications\Delivery;
use App\Push\WebPushChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Повтор пуша устройствам, где сервер пушей не ответил (19.11.2026: за две недели ~10 пушей менеджерам пропали на
 * «Could not resolve host: web.push.apple.com»). Тот же готовый JSON в те же подписки через 1, 5 и 15 минут; каждый раз —
 * только тем, где снова сбой, принявшие второй раз не получают. Итог — строкой журнала доставки (`sent` «со 2-й попытки»
 * или `failed` после третьего повтора).
 */
final class RetryPush implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Отсрочка перед повтором N (1-й — через минуту). */
    public const DELAYS = [60, 300, 900];

    public int $tries = 1;

    /**
     * @param  array{kind: string, label: string, category: string, important: bool}  $meta
     * @param  array<int, string>  $payloads  id подписки → готовый JSON
     */
    public function __construct(public string $noticeId, public int $userId, public array $meta, public array $payloads, public int $attempt) {}

    public function handle(): void
    {
        [$accepted, $again, $reasons] = WebPushChannel::resend($this->payloads);
        $reason = implode('; ', array_unique($reasons)) ?: 'не принят';
        $next = $this->attempt < count(self::DELAYS) ? array_intersect_key($this->payloads, array_flip($again)) : [];
        if ($next) {
            self::dispatch($this->noticeId, $this->userId, $this->meta, $next, $this->attempt + 1)->delay(now()->addSeconds(self::DELAYS[$this->attempt]));
        }
        $this->record(match (true) {
            $accepted > 0 => ['sent', 'с '.($this->attempt + 1).'-й попытки', $accepted],
            (bool) $next => ['retry', 'попытка '.($this->attempt + 1).': '.$reason, null],
            default => ['failed', 'после повторов: '.$reason, 0],
        });
    }

    /** @param array{0: string, 1: string, 2: ?int} $row */
    private function record(array $row): void
    {
        [$status, $detail, $devices] = $row;
        Delivery::log($this->meta, $this->noticeId, $this->userId, 'push', $status, $detail, $devices);
    }
}
