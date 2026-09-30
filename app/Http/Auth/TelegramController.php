<?php

namespace App\Http\Auth;

use App\Notifications\TelegramChannel;
use App\Notifications\TestNotice;
use App\Telegram\Actions\UnlinkChat;
use App\Telegram\StartLink;
use App\Users\Impersonation;
use App\Users\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Telegram у аккаунта: отметки шторки подключения, состояние, тумблер, пробное сообщение, отключить и вход через бота.
 * Привязка идёт в самом боте (`/start` со ссылкой, UpdateHandler), здесь её нет.
 */
class TelegramController
{
    /**
     * Шторка подключения показалась сама или карточку скрыли — отметка, чтобы не навязываться (User::telegramMoments):
     * intro — больше никогда, bid — не раньше чем через неделю и не больше трёх раз, card — скрыта на 30 дней.
     * За человека не пишем: админ, вошедший за менеджера, шторки не видит.
     */
    public function seen(Request $request)
    {
        $user = $request->user();
        $moment = $request->string('moment')->toString();
        if (! Impersonation::active() && ! $user->telegram_chat_id && in_array($moment, ['intro', 'bid', 'card'], true)) {
            $s = $user->notification_settings ?? [];
            match ($moment) {
                'intro' => $s['telegram_intro'] = now()->toIso8601String(),
                'bid' => [$s['telegram_bid_at'], $s['telegram_bid_n']] = [now()->toIso8601String(), ($s['telegram_bid_n'] ?? 0) + 1],
                'card' => $s['telegram_card_hidden'] = now()->addDays(30)->toIso8601String(),
            };
            $user->update(['notification_settings' => $s]);
        }

        return $request->expectsJson() ? response()->noContent() : back();
    }

    /** Шторка ждёт «Запустить»: событие хаба могло не дойти — вернувшись на вкладку, она спрашивает сама. */
    public function state(Request $request)
    {
        return response()->json(['linked' => $request->user()->telegram_chat_id !== null]);
    }

    /** Тумблер «Уведомления» в шторке Telegram профиля. */
    public function update(Request $request)
    {
        $user = $request->user();
        Impersonation::active() || $user->update(['notification_settings' => ['telegram' => $request->boolean('on')] + ($user->notification_settings ?? [])]);

        return back()->with('toast', $request->boolean('on') ? 'Уведомления в Telegram включены' : 'Уведомления в Telegram выключены');
    }

    /** «Отправить пробное» — только в Telegram, мимо ленты и пуша. */
    public function test(Request $request, TelegramChannel $channel)
    {
        $channel->send($request->user(), new TestNotice);

        return back()->with('toast', 'Отправили в Telegram');
    }

    public function destroy(Request $request, UnlinkChat $unlink)
    {
        Impersonation::active() || $unlink($request->user());

        return back()->with('toast', 'Telegram отвязан');
    }

    /**
     * Страница входа ждёт кнопку в чате. Спрашивает, когда пришло событие из хаба или человек вернулся из Telegram:
     * 202 — ещё ждём, 410 — «Это не я» или срок вышел, иначе вход и куда идти.
     */
    public function login(Request $request, string $token)
    {
        $attempt = $request->session()->get('telegram.login') === $token ? StartLink::attempt($token) : null;
        if (! $attempt || $attempt['state'] === 'no') {
            return response()->json(['state' => 'gone'], 410);
        }
        if ($attempt['state'] === 'wait') {
            return response()->json(['state' => 'wait'], 202);
        }
        $user = User::find($attempt['user'] ?? 0);
        if (! $user || $user->isRejected() || $user->telegram_chat_id === null) {
            return response()->json(['state' => 'gone'], 410);
        }
        StartLink::forget($token);
        $request->session()->forget('telegram.login');
        Auth::login($user, true);
        $request->session()->regenerate();

        return response()->json(['state' => 'in', 'href' => redirect()->intended(LoginController::home())->getTargetUrl()]);
    }
}
