<?php

namespace App\Telegram;

use App\Support\Surface;
use App\Users\User;

/**
 * «Войти через XCar» из бота (21.10.2026, владелец: «скинул ссылку на бота — и менеджеры залогинились в свои аккаунты»;
 * по номеру телефона не ищем — в Telegram он часто другой). Кнопка ведёт на `xcar.ru/telegram/connect?bot=…`: там вход
 * xcar, а после него — ссылка обратно в бот с подписанной привязкой аккаунта (`StartLink::link`). Привязывается тот
 * Telegram, из которого её откроют: id в нашей ссылке ничего бы не доказал — её можно переслать, и к аккаунту встал бы
 * чужой Telegram. Id личного чата у обоих ботов один — второй бот узнаёт человека сам.
 */
final class ConnectLink
{
    public const MAIN = 'main';

    public const OFFERS = 'offers';

    public static function url(string $bot): string
    {
        return Surface::Site->url('/telegram/connect?bot='.($bot === self::OFFERS ? self::OFFERS : self::MAIN));
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
