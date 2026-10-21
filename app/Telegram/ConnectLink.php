<?php

namespace App\Telegram;

use App\Support\Surface;
use App\Users\User;
use Illuminate\Support\Facades\URL;

/**
 * «Войти через XCar» из бота (21.10.2026, владелец: «скинул ссылку на бота — и менеджеры залогинились в свои аккаунты»;
 * по номеру телефона не ищем — в Telegram он часто другой): подписанная ссылка на `xcar.ru/telegram/connect` — какой бот,
 * id в Telegram, имя и @username того, кто нажал. Живёт 30 минут, подключает один раз (`TelegramConnectController`);
 * вход — обычный вход xcar. Id личного чата у обоих ботов один — второй бот узнаёт человека сам.
 */
final class ConnectLink
{
    public const MINUTES = 30;

    public const MAIN = 'main';

    public const OFFERS = 'offers';

    /** @param  array{id?: int, first_name?: string, last_name?: string, username?: string}  $from  поле `from` сообщения */
    public static function url(string $bot, array $from): string
    {
        // Подпись — без хоста и схемы (за прокси запрос приходит по http): адрес на сайт — `Surface::Site`.
        return Surface::Site->url(URL::temporarySignedRoute('telegram.connect', now()->addMinutes(self::MINUTES), array_filter([
            'bot' => $bot,
            'tg' => (int) ($from['id'] ?? 0),
            'name' => trim(($from['first_name'] ?? '').' '.($from['last_name'] ?? '')) ?: null,
            'u' => $from['username'] ?? null,
        ], fn ($v) => $v !== null && $v !== ''), absolute: false));
    }

    /** Кому из ботов можно подключить этого человека — та же дверь, что «Подключить Telegram» в приложении, без «ещё не привязан». */
    public static function allows(User $user): bool
    {
        return ($user->isManager() || $user->isBuyer() || $user->isSuperBuyer() || $user->isAdmin() || $user->isModerator() || $user->isParking()
            || $user->isParkAdmin() || $user->isLogist() || $user->isDriver() || $user->isContractor())
            && ! $user->is_demo && ! $user->isRejected() && $user->linksOwnTelegram();
    }

    /** «Подключите оба»: строка про два бота для /start незнакомому. */
    public static function intro(): string
    {
        $main = app(Bot::class)->username();
        $offers = app(Offers\OffersBot::class)->username();

        return "<b>У XCar два бота</b>\n"
            .($main ? "@{$main}: ваши сделки, ваш ход, оплаты и сообщения чатов\n" : '')
            .($offers ? "@{$offers}: новые предложения для менеджеров\n" : '')
            ."Подключите оба. Чтобы бот знал вас, войдите через XCar";
    }
}
