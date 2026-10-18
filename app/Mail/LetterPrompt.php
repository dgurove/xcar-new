<?php

namespace App\Mail;

use App\Users\User;
use Illuminate\Support\Facades\Cache;

/**
 * Письмо, которое надо открыть окном, — у человека на сервере, а не одноразовой меткой сессии (18.10.2026: Андрей принял
 * 8 подтверждений Альфы, окно открылось у 4). Метку `session('letters')` забирал первый запрос после «Принять» — живое
 * перечитывание списка, значок, предзагрузка соседней карточки, — и окно не открывалось. Здесь письмо забирает только
 * ответ, который рисует окно (`x-mail.window`, `x-ui.detail-streams`), и не предзагрузка; не забрали за 15 минут — гаснет,
 * письмо всё равно ждёт на шаге сделки («Написать поставщику»).
 */
final class LetterPrompt
{
    private const TTL = 15;

    public static function push(User $user, string $url): void
    {
        Cache::lock(self::key($user->id).':lock', 5)->block(3, function () use ($user, $url) {
            $list = array_values(array_unique([...Cache::get(self::key($user->id), []), $url]));
            Cache::put(self::key($user->id), $list, now()->addMinutes(self::TTL));
        });
    }

    /** Первое ждущее письмо — один раз за запрос: и окно страницы, и поток карточки спрашивают, открывает одно. */
    public static function take(): ?string
    {
        $request = request();
        $user = $request->user();
        if (! $user || ! $request->isMethod('GET') || $request->prefetch() || $request->headers->has('X-Live-Rows') || $request->headers->has('X-Count')) {
            return null;
        }
        if ($request->attributes->has('letter-prompt')) {
            return null;
        }
        $url = Cache::lock(self::key($user->id).':lock', 5)->block(3, function () use ($user) {
            $list = Cache::get(self::key($user->id), []);
            $first = array_shift($list);
            $list ? Cache::put(self::key($user->id), $list, now()->addMinutes(self::TTL)) : Cache::forget(self::key($user->id));

            return $first;
        });
        $request->attributes->set('letter-prompt', $url);

        return $url;
    }

    private static function key(int $user): string
    {
        return 'letter-prompt:'.$user;
    }
}
