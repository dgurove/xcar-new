<?php

namespace App\Http\Auth;

use App\Live\Topics;
use App\Users\Access\CodeRefused;
use App\Users\Access\Confirm;
use App\Users\Access\Lock;
use App\Users\Access\SendCode;
use App\Users\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Throwable;

/**
 * Вход кодом из Telegram (07.10.2026): логин, телефон или почта → код в бот → шесть цифр или «Войти» в боте. Ответ
 * одинаковый, есть такой человек или нет. Менеджер под замком этим же входом заводит браузер устройством.
 */
final class CodeLoginController
{
    public function send(Request $request, SendCode $send)
    {
        $data = $request->validate(['login' => ['required', 'string', 'max:120']]);
        [$field, $value] = LoginController::identify(trim($data['login']));
        $user = User::where($field, $value)->first();
        Lock::rememberCode($request, null);
        $nonce = Str::random(43);
        if ($user && $user->telegram_chat_id && $user->isApproved() && ! $user->isRejected() && ! RateLimiter::tooManyAttempts($key = 'lock-code:'.$user->id, 12)) {
            RateLimiter::hit($key, 3600);
            // Сбой отправки — тот же ответ, что и всем: иначе по нему узнавали бы, чей это телефон. Причина — в лог.
            try {
                $nonce = $send($request, $user, 'login')->nonce;
            } catch (Throwable $e) {
                LockController::sendFailed($e, $user->id);
            }
        }

        return response()->json(['nonce' => $nonce, 'resend' => 30, 'topics' => implode(',', Topics::here(null))]);
    }

    public function verify(Request $request, Confirm $confirm)
    {
        try {
            [$access, $key] = $confirm->check($request, ['login'], $request->input('code'), $request->input('key'), $request->input('sig'));
        } catch (CodeRefused $e) {
            return $e->toResponse();
        }
        $user = $access->user;
        if (! $user || ! $user->isApproved() || $user->isRejected() || $user->telegram_chat_id === null) {
            return (new CodeRefused('gone', 'Код устарел, запросите новый'))->toResponse();
        }
        Auth::login($user, true);
        $request->session()->regenerate();
        $confirm->finish($request, $user, $access, $key);

        return response()->json(['href' => redirect()->intended(LoginController::home())->getTargetUrl()]);
    }
}
