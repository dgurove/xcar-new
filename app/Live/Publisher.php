<?php

namespace App\Live;

use App\Live\Jobs\PublishRefresh;
use App\Support\Nav;
use App\Support\Surface;
use Illuminate\Support\Facades\Cache;
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
    /** Окно `refreshSoon`, секунды. */
    public const SOON = 2;

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

    /**
     * Событие без строки в ленте (письмо в ящик, файлы из письма, импорт) — тихая карточка уведомления. Живёт там,
     * чью тему слушают: `park` — на парковке, `staff` — в CRM.
     */
    public function toast(array|string $topics, string $message, ?string $href = null, string $icon = 'mail'): void
    {
        $surface = in_array(Topics::PARK, (array) $topics, true) ? Surface::Park : (in_array(Topics::STAFF, (array) $topics, true) ? Surface::Crm : null);
        $this->notice($topics, Banner::plain($message, $href, $icon, $surface));
    }

    /** Карточка уведомления (`Banner`): клиент ставит её в угол, важную — со звуком и до нажатия. */
    public function notice(array|string $topics, array $banner): void
    {
        $this($topics, 'notice', $banner);
    }

    /** Строки ленты об этих объектах погасли — их карточки закрываются во всех вкладках и на всех устройствах. */
    public function noticesRead(array|string $topics, array $subjects): void
    {
        $this($topics, 'notices-read', ['subjects' => array_values($subjects)]);
    }

    /**
     * Перечитать открытые страницы. Запрос Turbo, из которого это пришло (`X-Turbo-Request-Id`), едет с событием: у автора
     * правки Turbo его узнаёт и страницу второй раз не перечитывает — ответ на его форму уже свежий.
     */
    public function refresh(array|string $topics, array $paths, ?string $request = null): void
    {
        $request ??= request()->header('X-Turbo-Request-Id');
        $this($topics, 'refresh', ['paths' => $paths] + (is_string($request) && $request !== '' ? ['request' => $request] : []));
    }

    /**
     * То же для того, что правят пачкой (машина: «✨», импорт, команда по сотне ТС): первое — сразу, всё, что пришло за
     * окно `SOON`, — одним перечитыванием в конце (`PublishRefresh`), а не морф открытой CRM на каждую машину.
     */
    public function refreshSoon(array|string $topics, array $paths): void
    {
        $key = md5(implode(',', (array) $topics).'|'.implode(',', $paths));
        if (Cache::add("live:soon:{$key}", 1, self::SOON)) {
            $this->refresh($topics, $paths);
        } elseif (Cache::add("live:tail:{$key}", 1, 60)) {
            // Хвост несёт запрос автора: у него Turbo второй раз страницу не перечитывает (16.10.2026 — лишний морф под
            // открытой карточкой ловил гонку с переходом к следующей строке).
            $request = request()->header('X-Turbo-Request-Id');
            PublishRefresh::dispatch((array) $topics, $paths, $key, is_string($request) ? $request : null)->delay(self::SOON);
        }
    }

    /**
     * Карточка в ленте: по умолчанию всем в каталоге; покупателям — в их личные темы, каталог они не слушают. `only` —
     * только перерисовать, если она на экране (правка машины): новой плиткой она не въезжает.
     */
    public function card(int $number, array|string $topics = Topics::CATALOG, bool $only = false): void
    {
        $this($topics, 'card', ['number' => $number] + ($only ? ['only' => true] : []));
    }

    public function badges(array|string $topics): void
    {
        $this($topics, 'badges');
    }
}
