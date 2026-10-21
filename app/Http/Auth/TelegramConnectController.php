<?php

namespace App\Http\Auth;

use App\Telegram\Bot;
use App\Telegram\ConnectLink;
use App\Telegram\Offers\Handler;
use App\Telegram\Offers\OffersBot;
use App\Telegram\StartLink;
use Illuminate\Http\Request;

/**
 * «Войти через XCar» из бота (`ConnectLink`, 21.10.2026): гость — на обычный вход, оттуда обратно; вошедший получает
 * кнопку «Подключить» — ссылку в бот с подписанной привязкой своего аккаунта (`StartLink::link`, сутки). Привязку делает
 * бот по `/start` от того, кто нажал. Бот предложений служит менеджерам — покупателю кнопка ведёт в основной бот.
 */
final class TelegramConnectController
{
    public function show(Request $request)
    {
        $user = $request->user();
        if (! $user) {
            return redirect()->guest('/login');
        }
        $offers = $request->query('bot') === ConnectLink::OFFERS && Handler::eligible($user);
        $bot = $offers ? app(OffersBot::class) : app(Bot::class);
        $allowed = ConnectLink::allows($user);

        return view('auth.telegram-connect', [
            'user' => $user,
            'allowed' => $allowed,
            'bot' => $bot->username(),
            'link' => $allowed ? $bot->startUrl(StartLink::link($user)) : null,
        ]);
    }
}
