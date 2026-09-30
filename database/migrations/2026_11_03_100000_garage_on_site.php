<?php

use App\Support\Paths;
use App\Support\Surface;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Структура сайта 30.09.2026 (решение владельца): гараж — раздел сайта `/garage` вместо хоста `garage.xcar.ru`,
 * сделки и покупатели ушли из кабинета (`/deals`, `/buyers`). Ссылки в ленте уведомлений переписываются на новые
 * адреса, чтобы не жить на переадресации: у гаража был абсолютный адрес другого хоста. Подписки на пуш, оформленные
 * в приложении «Гараж XCar», снимаются: того приложения больше нет, иначе каждый пуш приходил бы дважды.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('notifications')
            ->whereRaw("(data->>'href' like ? or data->>'href' like ?)", ['%://garage.%', '/account/%'])
            ->select(['id', DB::raw("data->>'href' as href")])
            ->lazyById(200)
            ->each(function ($row) {
                $new = $this->moved($row->href);
                if ($new !== null && $new !== $row->href) {
                    DB::update("update notifications set data = jsonb_set(data, '{href}', to_jsonb(?::text)) where id = ?", [$new, $row->id]);
                }
            });

        DB::table('push_subscriptions')->where('host', 'like', 'garage.%')->delete();
    }

    /** Новый адрес: гаражный хост — в раздел сайта; относительный путь переехавшего раздела — по Paths::MOVED. */
    private function moved(string $href): ?string
    {
        if (preg_match('#^https?://garage\.[^/]+(/[^?]*)?(\?.*)?$#', $href, $m)) {
            return Surface::Site->url('/garage'.rtrim($m[1] ?? '', '/')).($m[2] ?? '');
        }
        if (! str_starts_with($href, '/')) {
            return null;
        }
        [$path, $query] = array_pad(explode('?', $href, 2), 2, null);
        $new = Paths::moved($path);

        return $new === null ? null : $new.($query !== null ? '?'.$query : '');
    }

    public function down(): void {}
};
