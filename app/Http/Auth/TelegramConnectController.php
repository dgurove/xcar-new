<?php

namespace App\Http\Auth;

use App\Telegram\Bot;
use App\Telegram\ConnectLink;
use App\Telegram\Offers\Handler;
use App\Telegram\Offers\OffersBot;
use App\Telegram\UpdateHandler;
use App\Users\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * «Войти через XCar» из бота (`ConnectLink`, 21.10.2026): гость — на обычный вход, оттуда обратно; вошедший видит, чей
 * Telegram подключает (имя и @username — чужую ссылку так не подсунуть), и жмёт «Подключить». Подключается бот, из которого
 * пришёл: основной — `UpdateHandler::connected`, бот предложений — `Handler::connected`. Ссылка — один раз.
 */
final class TelegramConnectController
{
    public function show(Request $request)
    {
        if (! $request->hasValidRelativeSignature()) {
            return $this->expired();
        }
        if (! $request->user()) {
            return redirect()->guest('/login');
        }

        return view('auth.telegram-connect', ['tg' => $this->telegram($request), 'user' => $request->user(), 'allowed' => ConnectLink::allows($request->user())]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        if (! $request->hasValidRelativeSignature() || ! Cache::add('telegram:connect:'.$request->query('signature'), true, ConnectLink::MINUTES * 60)) {
            return $this->expired();
        }
        abort_unless($user && ConnectLink::allows($user), 403);
        $tg = $this->telegram($request);
        $tg['bot'] === ConnectLink::OFFERS
            ? app(Handler::class)->connected($user, $tg['id'])
            : app(UpdateHandler::class)->connected($user, $tg['id'], $tg['username']);

        return view('auth.telegram-connect', ['tg' => $tg, 'user' => $user, 'allowed' => true, 'done' => true]);
    }

    /** @return array{id: int, bot: string, name: ?string, username: ?string, link: ?string} */
    private function telegram(Request $request): array
    {
        $bot = $request->query('bot') === ConnectLink::OFFERS ? ConnectLink::OFFERS : ConnectLink::MAIN;
        $name = ($bot === ConnectLink::OFFERS ? app(OffersBot::class) : app(Bot::class))->username();

        return ['id' => (int) $request->query('tg'), 'bot' => $bot, 'name' => $request->query('name'), 'username' => $request->query('u'),
            'link' => $name ? 'https://t.me/'.$name : null];
    }

    private function expired()
    {
        return response()->view('auth.telegram-connect', ['expired' => true], 410);
    }
}
