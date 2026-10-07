<?php

namespace App\Users\Access;

use App\Support\UserAgent;
use App\Telegram\Bot;
use App\Users\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

use function Illuminate\Support\defer;

/**
 * Код в Telegram одним сообщением: цифры первой строкой (видны прямо в уведомлении, нажатие копирует), браузер второй,
 * кнопки «Войти» или «Продолжить» и «Это не я». Живой код у человека один: прежние гаснут, их сообщения удаляются,
 * чтобы чат не копил цифры. Отправка не удалась — исключение наружу: экран скажет, что делать.
 */
final class SendCode
{
    public function __construct(private Bot $bot) {}

    /** @param 'login'|'unlock' $purpose */
    public function __invoke(Request $request, User $user, string $purpose): AccessCode
    {
        $code = str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);
        $nonce = Str::random(43);
        $label = UserAgent::label($request->userAgent());
        $this->supersede($request, $user);

        $access = AccessCode::create([
            'user_id' => $user->id,
            'purpose' => $purpose,
            'topic' => Lock::topic($request),
            'code_hash' => AccessCode::hash($code, $nonce),
            'nonce' => $nonce,
            'label' => $label,
            'chat_id' => $user->telegram_chat_id,
            'expires_at' => now()->addSeconds(AccessCode::TTL),
        ]);
        Lock::rememberCode($request, $access->id);

        $login = $purpose === 'login';
        // Локально без бота код — в лог: экран проверяют глазами без Telegram.
        if (! $this->bot->configured() && app()->isLocal()) {
            Log::info('Код: '.$code, ['user' => $user->id, 'purpose' => $purpose]);

            return $access;
        }
        $message = $this->bot->sendForId((int) $user->telegram_chat_id, self::text($code, $login, $label), [[
            ['text' => $login ? 'Войти' : 'Продолжить', 'callback_data' => "code:{$access->id}:ok"],
            ['text' => 'Это не я', 'callback_data' => "code:{$access->id}:no"],
        ]], self::text('••••••', $login, $label));
        $access->update(['message_id' => $message ?: null]);

        return $access;
    }

    /**
     * Привязка Telegram из окна замка: запись без цифр и без сообщения, её номер едет в ссылке на бота (`StartLink::code`).
     * `/start` с ней привязывает чат и одобряет запись (UpdateHandler) — одобрение достаётся только браузеру с этой
     * записью в cookie, а не любой сессии того же человека. Живая запись этого браузера — она же.
     */
    public function link(Request $request, User $user): AccessCode
    {
        $live = AccessCode::where('user_id', $user->id)->where('purpose', 'link')->find(Lock::codeId($request));
        if ($live?->isLive() && ! $live->approved_at && $live->expires_at->gt(now()->addMinutes(2))) {
            return $live;
        }
        $nonce = Str::random(43);
        $access = AccessCode::create([
            'user_id' => $user->id,
            'purpose' => 'link',
            'topic' => Lock::topic($request),
            'code_hash' => AccessCode::hash(Str::random(16), $nonce),
            'nonce' => $nonce,
            'label' => UserAgent::label($request->userAgent()),
            'expires_at' => now()->addSeconds(AccessCode::LINK_TTL),
        ]);
        Lock::rememberCode($request, $access->id);

        return $access;
    }

    public static function text(string $code, bool $login, string $label): string
    {
        return 'Код <code>'.$code.'</code>'."\n".($login ? 'Вход на xcar.ru, ' : 'Продолжить на xcar.ru, ').e($label);
    }

    /**
     * Прежний код этого же браузера гаснет, его сообщение с цифрами уходит из чата — после ответа, страница его не ждёт.
     * Коды других браузеров не трогаем: иначе любой, кто знает телефон менеджера, гасил бы его код со страницы входа.
     */
    private function supersede(Request $request, User $user): void
    {
        $old = AccessCode::where('user_id', $user->id)->whereNull('used_at')->whereNull('denied_at')->find(Lock::codeId($request));
        if (! $old) {
            return;
        }
        $old->update(['used_at' => now()]);
        if ($old->message_id && $old->chat_id) {
            [$bot, $chat, $message, $id] = [$this->bot, (int) $old->chat_id, (int) $old->message_id, $old->id];
            defer(function () use ($bot, $chat, $message, $id) {
                try {
                    $bot->delete($chat, $message);
                } catch (Throwable $e) {
                    Log::info('Код: прежнее сообщение не удалилось', ['code' => $id, 'error' => $bot->scrub($e->getMessage())]);
                }
            });
        }
    }
}
