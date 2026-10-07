<?php

namespace App\Users\Access;

use App\Telegram\Bot;
use App\Users\User;
use Illuminate\Http\Request;

use function Illuminate\Support\defer;

/**
 * Подтверждение этого браузера: код, который он просил (id в его cookie), — введённые цифры или «Продолжить» в
 * Telegram, и подпись `nonce` ключом браузера. Код подходит только для своего дела (`$purposes`): кодом продления не
 * войти со страницы входа. Принятый код гаснет, сообщение в Telegram становится «Подтверждено 14:05» без цифр (после
 * ответа — страница Telegram не ждёт). Дальше — свой ключ у своего устройства: окно продлевается; иначе этот браузер
 * заводится устройством, прежнее выкидывается.
 */
final class Confirm
{
    public function __construct(private Bot $bot, private Enroll $enroll) {}

    /**
     * @param  list<string>  $purposes
     * @return array{0: AccessCode, 1: array{kty: string, crv: string, x: string, y: string}}
     */
    public function check(Request $request, array $purposes, ?string $typed, ?string $key, ?string $signature): array
    {
        $access = AccessCode::whereIn('purpose', $purposes)->find(Lock::codeId($request));
        if (! $access || ! $access->isLive()) {
            throw new CodeRefused('gone', $access?->denied_at ? 'Отклонено в Telegram' : 'Код устарел, запросите новый');
        }
        $digits = preg_replace('/\D/', '', (string) $typed);
        if ($digits !== '') {
            if (! $access->matches($digits)) {
                $access->increment('attempts');
                throw new CodeRefused($access->attempts >= AccessCode::ATTEMPTS ? 'gone' : 'wrong',
                    $access->attempts >= AccessCode::ATTEMPTS ? 'Код погашен, запросите новый' : 'Неверный код');
            }
        } elseif (! $access->approved_at) {
            throw new CodeRefused('wait');
        }
        $jwk = DeviceKey::parse($key);
        if (! $jwk || ! DeviceKey::verify($jwk, $access->nonce, $signature)) {
            throw new CodeRefused('key', 'Браузер не подтвердил ключ, обновите страницу');
        }

        return [$access, $jwk];
    }

    /** Код принят: погасить, сообщение — без цифр, окно — с этой минуты. Без замка (админ вошёл кодом) — только вход. */
    public function finish(Request $request, User $user, AccessCode $access, array $jwk): ?Device
    {
        $access->update(['used_at' => now()]);
        Lock::rememberCode($request, null);
        $this->settled($access, $access->purpose === 'login' ? 'Вошли ' : 'Подтверждено ');
        if (! Lock::applies($user)) {
            return null;
        }

        // У каждого хоста (xcar.ru, crm.xcar.ru) своя IndexedDB и свой ключ, а метка устройства — общая cookie: ключ хоста,
        // которого у устройства ещё нет, привязывается (хост человеку открыт — /lock только там), чужой ключ — новое устройство.
        [, $device] = Lock::check($request);
        $host = $request->getHost();
        $known = $device?->keys()[$host] ?? null;
        if ($device?->owns(Lock::token($request)) && ($known === null || DeviceKey::same($known, $jwk))) {
            $device->update(['public_key' => [$host => $jwk] + $device->keys(), 'verified_until' => now()->addMinutes(Lock::minutes()), 'ip' => $request->ip()]);
            Lock::forget($request);

            return $device;
        }

        return ($this->enroll)($request, $user, $jwk);
    }

    /** Сообщение с кодом после решения: цифры и кнопки уходят — после ответа, подтверждение Telegram не ждёт. */
    public function settled(AccessCode $access, string $word): void
    {
        if ($access->chat_id && $access->message_id) {
            [$bot, $chat, $message, $text] = [$this->bot, (int) $access->chat_id, (int) $access->message_id, $word.now()->format('H:i')."\n".e($access->label)];
            defer(fn () => $bot->edit($chat, $message, $text));
        }
    }
}
