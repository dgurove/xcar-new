<?php

namespace App\Cars;

use App\Live\Publisher;
use App\Live\Topics;
use App\Offers\Offer;
use App\Park\Vehicle;
use Illuminate\Support\Facades\Cache;

/**
 * Ход задач с файлами машины — одно место (26.10.2026, приборка: было пять механизмов, а разбор архива из редактора и
 * разбор писем писали ход в один ключ и стирали его друг у друга). У машины — список задач: `mail:{ветка}` (файлы письма,
 * встаёт при постановке в очередь), `archive:{файл}` (архив, брошенный в «Фото»), `migtorg` (кадры лота). Запись —
 * `{stage, i, n, at}`. Ключ — машина, а не дорожка: файлы у машины одни, ход видят и продажа, и ТС. Задача, что
 * десять минут не двигалась (убили выкладкой, ждёт повтора), не считается — кольцо не крутится вечно.
 */
final class Progress
{
    public const MAIL = 'mail';

    public const ARCHIVE = 'archive';

    public const MIGTORG = 'migtorg';

    private const TTL = 1800;

    private const STALE = 600;

    /** Задача шагнула: подпись, что сделано из скольких; не названное — как было (заглушки в ряду фото не мигают). */
    public static function step(Offer|Vehicle|Car|int $car, string $task, ?string $stage = null, ?int $i = null, ?int $n = null): void
    {
        self::change($car, function (array $tasks) use ($task, $stage, $i, $n) {
            $was = $tasks[$task] ?? [];
            $tasks[$task] = ['stage' => $stage ?? ($was['stage'] ?? null), 'i' => $i ?? ($was['i'] ?? 0), 'n' => $n ?? ($was['n'] ?? null), 'at' => time()];

            return $tasks;
        });
        self::announce($car, final: false);
    }

    public static function done(Offer|Vehicle|Car|int $car, string $task): void
    {
        self::change($car, function (array $tasks) use ($task) {
            unset($tasks[$task]);

            return $tasks;
        });
        self::announce($car, final: true);
    }

    /**
     * Ход шагнул — событие `files` хаба: открытый ряд фото переспрашивает себя (`attach_controller`), опроса нет. Шаги — не
     * чаще раза в 2 с на машину (кадр ложится за 1,5 с), конец задачи — всегда.
     */
    private static function announce(Offer|Vehicle|Car|int $car, bool $final): void
    {
        $id = self::id($car);
        if ($id && ($final || Cache::add("progress-pub:{$id}", 1, 2))) {
            app(Publisher::class)([Topics::STAFF, Topics::PARK], 'files', ['car' => $id]);
        }
    }

    /** Самая свежая задача вида (`mail` — любой ветки): `{stage, i, n, at}` или null. */
    public static function of(Offer|Vehicle|Car|int $car, string ...$kinds): ?array
    {
        $found = array_filter(self::tasks($car), fn ($task) => in_array(explode(':', $task, 2)[0], $kinds, true), ARRAY_FILTER_USE_KEY);
        uasort($found, fn ($a, $b) => $b['at'] <=> $a['at']);

        return $found ? reset($found) : null;
    }

    /** Есть ли ещё задача вида, кроме этой: черновик из письма читается, когда доехали файлы всех веток. */
    public static function busy(Offer|Vehicle|Car|int $car, string $kind, ?string $except = null): bool
    {
        foreach (array_keys(self::tasks($car)) as $task) {
            if ($task !== $except && explode(':', $task, 2)[0] === $kind) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, array{stage: ?string, i: int, n: ?int, at: int}> живые задачи */
    public static function tasks(Offer|Vehicle|Car|int $car): array
    {
        $id = self::id($car);

        return $id ? array_filter((array) Cache::get(self::key($id), []), fn ($t) => time() - ($t['at'] ?? 0) < self::STALE) : [];
    }

    private static function change(Offer|Vehicle|Car|int $car, callable $fn): void
    {
        if (! $id = self::id($car)) {
            return;
        }
        // Задачи одной машины идут разными работниками: список правится под замком, иначе одна затирала бы другую.
        Cache::lock("progress-lock:{$id}", 5)->block(5, function () use ($id, $fn) {
            $tasks = $fn((array) Cache::get(self::key($id), []));
            $tasks ? Cache::put(self::key($id), $tasks, self::TTL) : Cache::forget(self::key($id));
        });
    }

    private static function key(int $id): string
    {
        return "progress:car:{$id}";
    }

    private static function id(Offer|Vehicle|Car|int $car): ?int
    {
        return match (true) {
            is_int($car) => $car,
            $car instanceof Car => $car->id,
            default => $car->car_id,
        };
    }
}
