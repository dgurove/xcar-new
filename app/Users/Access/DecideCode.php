<?php

namespace App\Users\Access;

use App\Live\Publisher;
use App\Telegram\Bot;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Кнопка под кодом в Telegram. «Продолжить» / «Войти» — браузер, который ждёт, узнаёт сразу (событие в тему его сессии)
 * и заканчивает сам, подписав `nonce` своим ключом. «Это не я» — код гаснет, браузер видит «Отклонено», админам —
 * строка: кто-то просил войти под менеджером.
 */
final class DecideCode
{
    public function __construct(private Publisher $publish, private Bot $bot) {}

    public function __invoke(AccessCode $access, bool $allow): void
    {
        $access->update($allow ? ['approved_at' => now()] : ['denied_at' => now()]);
        ($this->publish)($access->topic, 'lock', ['state' => $allow ? 'approved' : 'denied']);
        if ($allow) {
            return;
        }
        $text = '«Это не я» у '.e((string) $access->user?->name).': '.($access->purpose === 'login' ? 'вход' : 'продолжение').' на xcar.ru, '.e($access->label);
        foreach ($this->bot->ownerChats() as $chat) {
            try {
                $this->bot->send($chat, $text);
            } catch (Throwable $e) {
                Log::warning('Telegram: «Это не я» не ушло админу', ['chat' => $chat, 'error' => $this->bot->scrub($e->getMessage())]);
            }
        }
    }
}
