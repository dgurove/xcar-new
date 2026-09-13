<?php

namespace App\Live;

use App\Support\Nav;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Публикация в хаб. Всегда private=on: кому доставить, решает список тем в
 * токене подписчика. Живое обновление — удобство, а не обязательство:
 * хаб недоступен — пишем в лог и живём дальше.
 */
final class Publisher
{
    public function __invoke(array|string $topics, string $type, array $data = []): void
    {
        // Событие для сотрудников — счётчики Nav::staffCounts пересчитываются (и без хаба тоже).
        if (in_array($type, ['badges', 'refresh'], true) && array_intersect([Topics::STAFF, Topics::PARK], (array) $topics)) {
            Nav::forgetStaffCounts();
        }
        $hub = (string) config('xcar.mercure.hub');
        if ($hub === '' || ! config('xcar.mercure.publisher_key')) {
            return;
        }
        $body = implode('&', array_map(fn ($t) => 'topic='.rawurlencode($t), (array) $topics))
            .'&'.http_build_query(['type' => $type, 'data' => json_encode($data, JSON_UNESCAPED_UNICODE), 'private' => 'on']);
        try {
            Http::withToken(Jwt::publisher())->withBody($body, 'application/x-www-form-urlencoded')->timeout(3)->post($hub)->throw();
        } catch (Throwable $e) {
            Log::warning('Хаб не принял событие', ['type' => $type, 'topics' => $topics, 'error' => $e->getMessage()]);
        }
    }

    public function toast(array|string $topics, string $message, ?string $href = null): void
    {
        $this($topics, 'toast', ['message' => $message, 'href' => $href]);
    }

    public function refresh(array|string $topics, array $paths): void
    {
        $this($topics, 'refresh', ['paths' => $paths]);
    }

    public function card(int $number): void
    {
        $this(Topics::CATALOG, 'card', ['number' => $number]);
    }

    public function badges(array|string $topics): void
    {
        $this($topics, 'badges');
    }
}
