<?php

namespace App\Mail;

use App\Http\Middleware\KeepFlashInBackground;
use App\Support\Surface;
use App\Users\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Письмо, которое надо открыть окном, — у человека на сервере, а не одноразовой меткой сессии (18.10.2026: Андрей принял
 * 8 подтверждений Альфы, окно открылось у 4). Метку `session('letters')` забирали запросы после «Принять» — живое
 * перечитывание списка, значок, предзагрузка соседней карточки, — и окно не открывалось. Здесь письмо забирает только
 * ответ CRM, который рисует окно (`x-mail.window`, `x-ui.detail-streams`), и не фоновый запрос
 * (`KeepFlashInBackground::background`): окно парковки письма CRM не заберёт. Не забрали за 15 минут — гаснет, письмо всё
 * равно ждёт на шаге сделки («Написать поставщику»). Замок занят — без исключений: «Принять» уже прошло, страница должна
 * нарисоваться; письмо в худшем случае откроют с шага.
 */
final class LetterPrompt
{
    private const TTL = 15;

    public static function push(User $user, string $url): void
    {
        self::locked($user->id, function () use ($user, $url) {
            Cache::put(self::key($user->id), array_values(array_unique([...Cache::get(self::key($user->id), []), $url])), now()->addMinutes(self::TTL));
        });
    }

    /** Первое ждущее письмо — один раз за запрос: и окно страницы, и поток карточки спрашивают, открывает одно. */
    public static function take(): ?string
    {
        $request = request();
        $user = $request->user();
        if (! $user || Surface::current() !== Surface::Crm || KeepFlashInBackground::background($request) || $request->attributes->has('letter-prompt')) {
            return null;
        }
        $request->attributes->set('letter-prompt', true);

        return self::locked($user->id, function () use ($user) {
            $list = Cache::get(self::key($user->id), []);
            $first = array_shift($list);
            $list ? Cache::put(self::key($user->id), $list, now()->addMinutes(self::TTL)) : Cache::forget(self::key($user->id));

            return $first;
        });
    }

    private static function locked(int $user, callable $fn): mixed
    {
        try {
            return Cache::lock(self::key($user).':lock', 5)->block(2, $fn);
        } catch (LockTimeoutException $e) {
            Log::warning('Окно письма: замок занят', ['user' => $user]);

            return null;
        }
    }

    private static function key(int $user): string
    {
        return 'letter-prompt:'.$user;
    }
}
