<?php

namespace App\Http\Auth;

use App\Notifications\TelegramChannel;
use App\Notifications\TestNotice;
use App\Telegram\Actions\UnlinkChat;
use App\Telegram\Offers\LoginUrl;
use App\Telegram\StartLink;
use App\Users\Access\DeviceKey;
use App\Users\Access\Enroll;
use App\Users\Access\Lock;
use App\Users\Access\TelegramEntry;
use App\Users\Impersonation;
use App\Users\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

/**
 * Telegram у аккаунта: отметки шторки подключения, состояние, тумблер, пробное сообщение, отключить и вход через бота.
 * Привязка идёт в самом боте (`/start` со ссылкой, UpdateHandler), здесь её нет.
 */
class TelegramController
{
    /**
     * Шторка подключения показалась сама или карточку скрыли — отметка, чтобы не навязываться (User::telegramMoments):
     * intro — больше никогда, bid — не раньше чем через неделю и не больше трёх раз, card — скрыта на 30 дней.
     * reach — карточка «Не пропускайте…» логистики и парковки (`User::offersReach`): `never` или неделя; она и про пуши,
     * поэтому пишется и у подключённого Telegram. За человека не пишем: админ, вошедший за менеджера, шторки не видит.
     */
    public function seen(Request $request)
    {
        $user = $request->user();
        $moment = $request->string('moment')->toString();
        if ($moment === 'reach' && ! Impersonation::active()) {
            $user->update(['notification_settings' => ['reach_hidden' => $request->input('for') === 'never'
                ? 'never' : now()->addWeek()->toIso8601String()] + ($user->notification_settings ?? [])]);

            return $request->expectsJson() ? response()->noContent() : back();
        }
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

    /**
     * Кнопка уведомления в Telegram (`TelegramChannel::button`, `login_url`): подпись Telegram — вход (`TelegramEntry`),
     * дальше — на свой путь. Подпись одна — вход один: адрес остаётся в истории браузера. Не сошлось, не привязан — просто
     * на путь, там обычный вход.
     */
    public function go(Request $request)
    {
        $to = (string) $request->query('to', '/');
        $path = str_starts_with($to, '/') && ! str_starts_with($to, '//') && ! str_contains($to, '\\') ? $to : '/';
        $tgId = LoginUrl::verify($request->query(), (string) config('xcar.telegram.token'));
        if ($tgId && Cache::add('telegram:login:'.$request->query('hash'), true, 86400)
            && ($user = User::where('telegram_chat_id', $tgId)->first())) {
            TelegramEntry::login($request, $user);
        }

        return redirect($path);
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
        // Под замком Telegram — единственный вход: отвязать его может только админ в CRM.
        if (Lock::applies($request->user())) {
            return back()->with('toast', 'Без Telegram вход закрыт, отвязать его может администратор');
        }
        Impersonation::active() || $unlink($request->user());

        return back()->with('toast', 'Telegram отвязан');
    }

    /**
     * Страница входа ждёт кнопку в чате. Спрашивает, когда пришло событие из хаба или человек вернулся из Telegram:
     * 202 — ещё ждём, 410 — «Это не я» или срок вышел, иначе вход и куда идти.
     */
    public function login(Request $request, string $token, Enroll $enroll)
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
        // Менеджер под замком: браузер подписывает токен попытки своим ключом и становится устройством.
        $key = null;
        if (Lock::enabled() && $user->underLock()) {
            $key = DeviceKey::parse($request->input('key'));
            if (! $key || ! DeviceKey::verify($key, $token, $request->input('sig'))) {
                return response()->json(['state' => 'key'], 422);
            }
        }
        StartLink::forget($token);
        $request->session()->forget('telegram.login');
        Auth::login($user, true);
        $request->session()->regenerate();
        if ($key) {
            $enroll($request, $user, $key);
        }

        return response()->json(['state' => 'in', 'href' => redirect()->intended(LoginController::home())->getTargetUrl()]);
    }
}
