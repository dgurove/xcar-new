<?php

namespace App\Http\Auth;

use App\Telegram\Actions\UnlinkChat;
use App\Telegram\StartLink;
use App\Users\Impersonation;
use App\Users\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Telegram у аккаунта: «Напомнить позже», отвязать и вход через бота.
 * Привязка идёт в самом боте (`/start` со ссылкой, UpdateHandler), здесь её нет.
 */
class TelegramController
{
    /**
     * Окошко «Привяжите Telegram» показали — не чаще раза в сутки; «Напомнить позже» — через 3 дня, дальше через неделю.
     * За человека не пишем: админ, вошедший за менеджера, окошка не видит и отложить за него не может.
     */
    public function later(Request $request)
    {
        $user = $request->user();
        if (! Impersonation::active() && ! $user->telegram_chat_id) {
            $s = $user->notification_settings ?? [];
            $asked = (int) ($s['telegram_asked'] ?? 0);
            $until = $request->boolean('shown') ? now()->addDay() : now()->addDays($asked === 0 ? 3 : 7);
            if (! isset($s['telegram_later']) || $until->greaterThan($s['telegram_later'])) {
                $s['telegram_later'] = $until->toIso8601String();
            }
            if (! $request->boolean('shown')) {
                $s['telegram_asked'] = $asked + 1;
            }
            $user->update(['notification_settings' => $s]);
        }

        return $request->expectsJson() ? response()->noContent() : back();
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
