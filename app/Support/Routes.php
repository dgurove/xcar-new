<?php

namespace App\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Несколько путей до одной службы с переключением (03.11.2026, владелец: «туннель регулярно падает, должны быть фоллбеки»):
 * до Telegram — туннель Цюриха (`zurich:9443`), туннель прода (`zurich:9445`) и напрямую (`direct`); до WhatsApp — оба
 * туннеля. Список — через запятую в настройке (`TELEGRAM_VIA`, `WHATSAPP_VIA`). Не соединилось (`ConnectionException`) —
 * путь две минуты идёт последним, запрос тут же повторяется по следующему; ответ службы (хоть 4xx/5xx) — путь живой.
 * Метка «лёг» — в общем кэше: её видят все процессы (очередь, опрос, бот предложений).
 */
final class Routes
{
    public const DIRECT = 'direct';

    private const DOWN_FOR = 120;

    /** @return list<string> пути из настройки; пусто — один `$empty` */
    public static function parse(?string $config, ?string $empty = null): array
    {
        $routes = array_values(array_filter(array_map('trim', explode(',', (string) $config)), fn ($r) => $r !== ''));

        return $routes ?: array_filter([$empty]);
    }

    /**
     * Живые сначала, в порядке настройки; лёгшие — в конце, тоже по порядку. Метки из кэша процесс перечитывает раз в 10 с:
     * не запрос к базе на каждое сообщение. @return list<string>
     */
    public static function ordered(string $service, array $routes): array
    {
        if (count($routes) < 2) {
            return $routes;
        }
        $memo = self::$down[$service] ?? null;
        if (! $memo || $memo['at'] < time() - 10) {
            $memo = self::$down[$service] = ['at' => time(), 'down' => array_values(array_filter($routes, fn ($r) => Cache::has(self::key($service, $r))))];
        }
        $down = fn (string $r) => in_array($r, $memo['down'], true);

        return [...array_values(array_filter($routes, fn ($r) => ! $down($r))), ...array_values(array_filter($routes, $down))];
    }

    /** @var array<string, array{at: int, down: list<string>}> */
    private static array $down = [];

    /**
     * Сделать запрос по первому живому пути, при сбое соединения — по следующему.
     *
     * @template T
     *
     * @param  callable(string): T  $attempt  запрос по этому пути
     * @return T
     */
    public static function over(string $service, array $routes, callable $attempt): mixed
    {
        $last = null;
        foreach (self::ordered($service, $routes) as $route) {
            try {
                return $attempt($route);
            } catch (ConnectionException $e) {
                $last = $e;
                if (count($routes) < 2) {
                    break;
                }
                self::$down[$service]['down'][] = $route;
                self::$down[$service]['at'] ??= time();
                if (Cache::add(self::key($service, $route), 1, self::DOWN_FOR)) {
                    Log::warning("{$service}: путь {$route} не соединился, дальше — следующий", ['error' => mb_substr($e->getMessage(), 0, 200)]);
                }
            }
        }

        throw $last ?? new ConnectionException("{$service}: путей нет");
    }

    private static function key(string $service, string $route): string
    {
        return 'route-down:'.$service.':'.$route;
    }
}
