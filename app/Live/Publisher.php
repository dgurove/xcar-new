<?php

namespace App\Live;

use App\Support\Nav;
use App\Support\Surface;
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

    public function refresh(array|string $topics, array $paths): void
    {
        $this($topics, 'refresh', ['paths' => $paths]);
    }

    /** Карточка в ленте: по умолчанию всем в каталоге; покупателям — в их личные темы, каталог они не слушают. */
    public function card(int $number, array|string $topics = Topics::CATALOG): void
    {
        $this($topics, 'card', ['number' => $number]);
    }

    public function badges(array|string $topics): void
    {
        $this($topics, 'badges');
    }
}
