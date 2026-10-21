<?php

namespace App\Users\Access;

use App\Support\Settings;
use App\Users\Impersonation;
use App\Users\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

/**
 * Замок менеджера (07.10.2026): где сейчас этот браузер.
 *   open   — своё устройство, окно идёт;
 *   frozen — своё устройство, окно кончилось: экран под окном подтверждения, данных сервер не отдаёт;
 *   enroll — устройства у этой сессии нет (первый вход после выкладки, вход «запомнить меня»): подтвердить через
 *            Telegram, и этот браузер станет устройством, прежнее выкинет;
 *   link   — Telegram не привязан: только привязка;
 *   kicked — у сессии было устройство, а теперь другое: вошли с другого — выход.
 * Под «Войти как», у админа и демо замка нет (null). Ответ помнится на запрос: его спрашивают сторож, темы хаба и вид.
 *
 * Облегчённый режим (08.10.2026, второй тумблер «Вход только с одного устройства»): без Telegram, окна и заморозки, только
 * `kicked` — вошли на другом устройстве. Устройство заводится без ключа браузера: при входе (EnrollOnLogin) и у сессий,
 * открытых до включения, на первой загрузке страницы (DeviceGate). Состояние в нём null или `kicked`.
 */
final class Lock
{
    public const OPEN = 'open';

    public const FROZEN = 'frozen';

    public const ENROLL = 'enroll';

    public const LINK = 'link';

    public const KICKED = 'kicked';

    /**
     * Метка устройства — своя cookie «id человека:метка» (в базе — sha256 метки), не в сессии: подтверждение меняло бы
     * сессию, а параллельный запрос (опрос окна) возвращал браузеру прежнюю cookie сессии, и подтверждённое терялось.
     * Номер человека в ней — чтобы чужая метка в том же браузере (вышел один, вошёл другой) не выкидывала вошедшего.
     */
    public const COOKIE = 'xcar-device';

    /**
     * Код, который просил этот браузер, — тоже своей cookie, не в сессии: опрос окна шёл параллельно и записывал сессию
     * без него. Зашифрована, как все cookie приложения: подделать номер нельзя.
     */
    public const CODE_COOKIE = 'xcar-code';

    private const MEMO = 'lock';

    public const SETTING = 'manager_lock';

    public const SINGLE = 'manager_single_device';

    /** Переключатель в CRM (Настройки → «Пользователи» → «Менеджеры»); `LOCK_MANAGERS=false` гасит его без CRM. */
    public static function enabled(): bool
    {
        return (bool) config('xcar.lock.enabled') && (bool) Settings::get(self::SETTING, false);
    }

    /** Одно устройство: свой тумблер или полная защита, она его включает. `LOCK_MANAGERS=false` гасит и его. */
    public static function single(): bool
    {
        return (bool) config('xcar.lock.enabled') && ((bool) Settings::get(self::SINGLE, false) || self::enabled());
    }

    public static function minutes(): int
    {
        return max(1, (int) config('xcar.lock.minutes'));
    }

    public static function applies(?User $user): bool
    {
        return $user !== null && $user->underLock() && self::enabled() && ! Impersonation::active();
    }

    /** Облегчённый режим касается этого человека: одно устройство без полной защиты. */
    public static function lite(?User $user): bool
    {
        return $user !== null && $user->underLock() && ! self::enabled() && self::single() && ! Impersonation::active();
    }

    /** @return array{0: ?string, 1: ?Device} */
    public static function check(?Request $request = null): array
    {
        $request ??= request();
        $user = $request->user();
        $full = self::applies($user);
        if (! ($full || self::lite($user)) || ! $request->hasSession()) {
            return [null, null];
        }
        $memo = $request->attributes->get(self::MEMO);
        if (is_array($memo) && $memo[0] === $user->id) {
            return $memo[1];
        }
        $token = self::token($request);
        $device = Device::live($user);
        $result = $full ? match (true) {
            // Было устройство, а теперь другое или никакого (вошли с другого, админ отвязал Telegram) — выход.
            is_string($token) && $token !== '' && ! $device?->owns($token) => [self::KICKED, $device],
            $user->telegram_chat_id === null => [self::LINK, null],
            $device?->owns($token) => [$device->isOpen() ? self::OPEN : self::FROZEN, $device],
            default => [self::ENROLL, $device],
        } : [match (true) {
            is_string($token) && $token !== '' && ! $device?->owns($token) => self::KICKED,
            // Вход по кнопке Telegram устройства не заводит (`TelegramEntry`) и живёт 12 часов.
            ($token === null || $token === '') && TelegramEntry::expired($request) => self::KICKED,
            default => null,
        }, $device];
        $request->attributes->set(self::MEMO, [$user->id, $result]);

        return $result;
    }

    public static function state(?Request $request = null): ?string
    {
        return self::check($request)[0];
    }

    /** Конец окна — для таймера; не своё устройство или окно кончилось — null. */
    public static function until(?Request $request = null): ?int
    {
        [$state, $device] = self::check($request);

        return $state === self::OPEN ? $device->verified_until->timestamp : null;
    }

    /** Данные сервер отдаёт: замка нет или окно идёт. */
    public static function passes(?Request $request = null): bool
    {
        return in_array(self::state($request), [null, self::OPEN], true);
    }

    /** Метка устройства этого браузера для вошедшего; метка другого человека — как нет никакой. */
    public static function token(Request $request): ?string
    {
        $value = $request->cookie(self::COOKIE);
        if (! is_string($value) || ! str_contains($value, ':')) {
            return null;
        }
        [$user, $token] = explode(':', $value, 2);

        return (int) $user === $request->user()?->id && $token !== '' ? $token : null;
    }

    /** Метка — браузеру надолго (окно держит срок, а не cookie) и в текущий запрос: дальше по нему считается уже она. */
    public static function remember(Request $request, int $user, string $token): void
    {
        $value = $user.':'.$token;
        Cookie::queue(Cookie::make(self::COOKIE, $value, 60 * 24 * 400, '/', config('session.domain'), $request->isSecure(), true, false, 'lax'));
        $request->cookies->set(self::COOKIE, $value);
        self::forget($request);
    }

    public static function forget(Request $request): void
    {
        $request->attributes->remove(self::MEMO);
    }

    /**
     * Тема хаба этого браузера: «подтвердили в Telegram», «вошли с другого устройства». Выводится из метки устройства
     * (у гостя — из id сессии) и в сессию не пишется. Новое устройство — новая метка, новая тема: «выкинуть» уходит
     * прежнему и его копиям, но не себе.
     */
    public static function topic(Request $request): string
    {
        $seed = self::token($request) ?? ($request->hasSession() ? $request->session()->getId() : '');

        return 'lock/'.substr(hash_hmac('sha256', $seed, 'lock-topic|'.config('app.key')), 0, 24);
    }

    public static function codeId(Request $request): ?int
    {
        $value = $request->cookie(self::CODE_COOKIE);

        return is_string($value) && ctype_digit($value) ? (int) $value : null;
    }

    /** Код этого браузера — на срок кода; null — забыть. */
    public static function rememberCode(Request $request, ?int $id): void
    {
        Cookie::queue($id === null
            ? Cookie::forget(self::CODE_COOKIE, '/', config('session.domain'))
            : Cookie::make(self::CODE_COOKIE, (string) $id, (int) ceil(AccessCode::TTL / 60), '/', config('session.domain'), $request->isSecure(), true, false, 'lax'));
        $id === null ? $request->cookies->remove(self::CODE_COOKIE) : $request->cookies->set(self::CODE_COOKIE, (string) $id);
    }
}
