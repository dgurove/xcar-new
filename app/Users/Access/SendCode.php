<?php

namespace App\Users\Access;

use App\Support\UserAgent;
use App\Telegram\Bot;
use App\Users\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

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
        $this->supersede($user);

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
        ]]);
        $access->update(['message_id' => $message ?: null]);

        return $access;
    }

    /**
     * Код, который не просит Telegram: Telegram только что привязали из этой же сессии — владение им уже доказано.
     * Браузеру остаётся подписать `nonce` своим ключом.
     */
    public function approved(Request $request, User $user): AccessCode
    {
        $nonce = Str::random(43);
        $access = AccessCode::create([
            'user_id' => $user->id,
            'purpose' => 'link',
            'topic' => Lock::topic($request),
            'code_hash' => AccessCode::hash(Str::random(16), $nonce),
            'nonce' => $nonce,
            'label' => UserAgent::label($request->userAgent()),
            'approved_at' => now(),
            'expires_at' => now()->addSeconds(AccessCode::TTL),
        ]);
        Lock::rememberCode($request, $access->id);

        return $access;
    }

    public static function text(string $code, bool $login, string $label): string
    {
        return 'Код <code>'.$code.'</code>'."\n".($login ? 'Вход на xcar.ru, ' : 'Продолжить на xcar.ru, ').e($label);
    }

    /** Прежние живые коды гаснут, их сообщения с цифрами уходят из чата. */
    private function supersede(User $user): void
    {
        $live = AccessCode::where('user_id', $user->id)->whereNull('used_at')->whereNull('denied_at')
            ->where('expires_at', '>', now()->subMinutes(10))->get();
        foreach ($live as $old) {
            $old->update(['used_at' => now()]);
            if ($old->message_id && $old->chat_id) {
                try {
                    $this->bot->delete((int) $old->chat_id, (int) $old->message_id);
                } catch (Throwable $e) {
                    Log::info('Код: прежнее сообщение не удалилось', ['code' => $old->id, 'error' => $this->bot->scrub($e->getMessage())]);
                }
            }
        }
    }
}
