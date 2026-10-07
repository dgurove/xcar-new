<?php

namespace App\Telegram;

use App\Support\UserAgent;
use App\Users\Access\AccessCode;
use App\Users\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Параметр `/start` у ссылки t.me на бота — не длиннее 64 знаков `[A-Za-z0-9_-]`.
 * Привязка: `L<id>-<срок>-<подпись>` без записи в базу, живёт сутки — карточка рисует её на каждой странице.
 * Вход: `I<случайное>` — попытка в кэше на 15 минут, токен помнит сессия браузера, который входит;
 * подтверждает её человек кнопкой в чате, привязанном к аккаунту.
 */
final class StartLink
{
    public const LOGIN_TTL = 900;

    public static function link(User $user): string
    {
        $body = 'L'.base_convert((string) $user->id, 10, 36).'-'.base_convert((string) now()->addDay()->timestamp, 10, 36);

        return $body.'-'.self::sign($body);
    }

    /**
     * Привязка из окна замка (`Access\SendCode::link`): `K<номер записи>-<подпись>`. Срок и браузер держит сама запись.
     */
    public static function code(AccessCode $access): string
    {
        $body = 'K'.base_convert((string) $access->id, 10, 36);

        return $body.'-'.self::sign($body);
    }

    /**
     * Что за ссылка: `['link', id, просрочена]`, `['login', токен]`, `['code', номер записи]` или null — чужая строка.
     *
     * @return array{0: 'link', 1: int, 2: bool}|array{0: 'login', 1: string}|array{0: 'code', 1: int}|null
     */
    public static function parse(string $payload): ?array
    {
        if (preg_match('/^L([0-9a-z]+)-([0-9a-z]+)-([0-9a-zA-Z]{16})$/', $payload, $m) === 1) {
            if (! hash_equals(self::sign('L'.$m[1].'-'.$m[2]), $m[3])) {
                return null;
            }

            return ['link', (int) base_convert($m[1], 36, 10), (int) base_convert($m[2], 36, 10) < now()->timestamp];
        }
        if (preg_match('/^K([0-9a-z]+)-([0-9a-zA-Z]{16})$/', $payload, $m) === 1) {
            return hash_equals(self::sign('K'.$m[1]), $m[2]) ? ['code', (int) base_convert($m[1], 36, 10)] : null;
        }
        if (preg_match('/^I[0-9a-zA-Z]{24}$/', $payload) === 1) {
            return ['login', $payload];
        }

        return null;
    }

    /** Попытка входа этого браузера: живая — та же, иначе новая. */
    public static function login(Request $request): string
    {
        $token = $request->session()->get('telegram.login');
        if (is_string($token) && self::attempt($token)) {
            return $token;
        }
        $token = 'I'.Str::random(24);
        Cache::put(self::key($token), ['state' => 'wait', 'device' => UserAgent::label($request->userAgent())], self::LOGIN_TTL);
        $request->session()->put('telegram.login', $token);

        return $token;
    }

    /** @return array{state: 'wait'|'ok'|'no', device: string, user?: int}|null */
    public static function attempt(string $token): ?array
    {
        return Cache::get(self::key($token));
    }

    /** @param 'ok'|'no' $state */
    public static function settle(string $token, string $state, int $user): void
    {
        if ($attempt = self::attempt($token)) {
            Cache::put(self::key($token), ['state' => $state, 'user' => $user] + $attempt, self::LOGIN_TTL);
        }
    }

    public static function forget(string $token): void
    {
        Cache::forget(self::key($token));
    }

    /** Кнопкам в чате нужен номер (`login:<n>:ok`): номер ведёт к токену. */
    public static function number(string $token): int
    {
        // Кэш в базе: increment по несуществующему ключу возвращает false — счётчик заводим заранее.
        Cache::add('telegram:login:seq', 0);
        $n = (int) Cache::increment('telegram:login:seq');
        Cache::put('telegram:login:n:'.$n, $token, self::LOGIN_TTL);

        return $n;
    }

    public static function tokenOf(int $number): ?string
    {
        return Cache::get('telegram:login:n:'.$number);
    }

    private static function key(string $token): string
    {
        return 'telegram:login:'.$token;
    }

    private static function sign(string $body): string
    {
        return substr(strtr(base64_encode(hash_hmac('sha256', $body, 'telegram-start|'.config('app.key'), true)), ['+' => '', '/' => '', '=' => '']), 0, 16);
    }
}
