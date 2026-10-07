<?php

namespace App\Http\Auth;

use App\Live\Topics;
use App\Telegram\Bot;
use App\Users\Access\AccessCode;
use App\Users\Access\CodeRefused;
use App\Users\Access\Confirm;
use App\Users\Access\Lock;
use App\Users\Access\SendCode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * Окно подтверждения менеджера (`x-lock.panel`): код в Telegram, проверка кода или кнопки «Продолжить», состояние для
 * возврата из Telegram. Сторож (`DeviceGate`) эти адреса пропускает и под замком.
 */
final class LockController
{
    public function state(Request $request)
    {
        $access = AccessCode::find(Lock::codeId($request));

        return response()->json([
            'state' => Lock::state($request),
            'until' => Lock::until($request),
            'code' => match (true) {
                ! $access => null,
                $access->denied_at !== null => 'denied',
                $access->approved_at !== null && $access->isLive() => 'approved',
                default => $access->isLive() ? 'wait' : 'gone',
            },
        ]);
    }

    /**
     * Код в Telegram: сам при заморозке и по «Отправить ещё раз». Живой код этого браузера моложе RESEND секунд — он же,
     * без второго сообщения (замёрзли две вкладки). Telegram привязали из этой сессии только что — код не нужен.
     */
    public function send(Request $request, SendCode $send)
    {
        $user = $request->user();
        $state = Lock::state($request);
        if (! in_array($state, [Lock::OPEN, Lock::FROZEN, Lock::ENROLL], true)) {
            return response()->json(['state' => $state], 409);
        }
        $since = (int) $request->session()->get(Lock::SESSION_LINKING);
        if ($state === Lock::ENROLL && $since && $user->telegram_linked_at && $user->telegram_linked_at->timestamp >= $since - 5) {
            return response()->json(['nonce' => $send->approved($request, $user)->nonce, 'approved' => true]);
        }
        $current = AccessCode::where('user_id', $user->id)->find(Lock::codeId($request));
        $age = $current ? (int) $current->created_at->diffInSeconds(now(), true) : PHP_INT_MAX;
        if ($current?->isLive() && $age < AccessCode::RESEND) {
            return $request->boolean('again')
                ? response()->json(['error' => 'Новый код через '.(AccessCode::RESEND - $age).' с'], 429)->header('Retry-After', AccessCode::RESEND - $age)
                : response()->json(['nonce' => $current->nonce, 'resend' => AccessCode::RESEND - $age, 'approved' => $current->approved_at !== null, 'topics' => implode(',', Topics::here($user))]);
        }
        if (RateLimiter::tooManyAttempts($hour = 'lock-code:'.$user->id, 12)) {
            return response()->json(['error' => 'Слишком много кодов, попробуйте через '.ceil(RateLimiter::availableIn($hour) / 60).' мин'], 429);
        }
        RateLimiter::hit($hour, 3600);
        try {
            $access = $send($request, $user, 'unlock');
        } catch (Throwable $e) {
            return self::sendFailed($e, $user->id);
        }

        return response()->json(['nonce' => $access->nonce, 'resend' => AccessCode::RESEND, 'topics' => implode(',', Topics::here($user))]);
    }

    public function verify(Request $request, Confirm $confirm)
    {
        $user = $request->user();
        if (! in_array(Lock::state($request), [Lock::OPEN, Lock::FROZEN, Lock::ENROLL], true)) {
            return response()->json(['state' => Lock::state($request)], 409);
        }
        try {
            [$access, $key] = $confirm->check($request, $request->input('code'), $request->input('key'), $request->input('sig'));
        } catch (CodeRefused $e) {
            return $e->toResponse();
        }
        abort_unless($access->user_id === $user->id, 409);
        $device = $confirm->finish($request, $user, $access, $key);

        return response()->json([
            'until' => $device?->verified_until->timestamp,
            'csrf' => csrf_token(),
            'topics' => implode(',', Topics::here($user)),
        ]);
    }

    /** Бот не отправил: человек его остановил — сказать, что нажать; иначе — повторить. */
    public static function sendFailed(Throwable $e, int $user)
    {
        if (Bot::chatGone($e)) {
            return response()->json(['state' => 'blocked', 'error' => 'Бот остановлен, откройте его и нажмите «Запустить»'], 409);
        }
        Log::warning('Код: Telegram не принял сообщение', ['user' => $user, 'error' => app(Bot::class)->scrub($e->getMessage())]);

        return response()->json(['error' => 'Telegram не ответил, попробуйте ещё раз'], 503);
    }
}
