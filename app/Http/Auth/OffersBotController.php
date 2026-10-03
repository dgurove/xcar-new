<?php

namespace App\Http\Auth;

use App\Telegram\Offers\Handler;
use App\Telegram\Offers\LoginUrl;
use App\Telegram\Offers\Subscriber;
use App\Users\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

/**
 * Бот предложений на сайте: вход по кнопке «Открыть в XCar» (`login_url` — Telegram открывает ссылку отдельным
 * браузером, где человека ещё нет) и строка «Предложения в Telegram» в начале каталога — скрыть на 30 дней.
 */
final class OffersBotController
{
    /** Куда ведёт кнопка: словом, а не путём — путь не перенаправление наружу. */
    private const TARGETS = ['offers' => '/offers', 'favorites' => '/account/favorites', 'invites' => '/account/invites'];

    public function open(Request $request, string $target)
    {
        $path = self::TARGETS[$target] ?? (ctype_digit($target) ? '/offers/'.$target : '/offers');
        $tgId = LoginUrl::verify($request->query(), (string) config('xcar.telegram.offers.token'));
        // Одна подпись — один вход: адрес остаётся в истории браузера.
        if ($tgId && Cache::add('offers-bot:login:'.$request->query('hash'), true, 86400)) {
            $user = Subscriber::where('chat_id', $tgId)->first()?->user;
            if ($user && Handler::eligible($user) && ! $user->isRejected() && Auth::id() !== $user->id) {
                Auth::login($user, true);
                $request->session()->regenerate();
            }
        }

        return redirect($path);
    }

    /** Шторка подписки вернулась из Telegram, а события хаба не было — подписался ли. */
    public function state(Request $request)
    {
        return response()->json(['linked' => Handler::subscribed($request->user())]);
    }

    /** × у строки подписки: не показывать месяц. */
    public function hide(Request $request)
    {
        /** @var User $user */
        $user = $request->user();
        $user->forceFill(['notification_settings' => ['offers_bot_hidden' => now()->addDays(30)->toDateString()] + ($user->notification_settings ?? [])])->save();

        return back();
    }
}
