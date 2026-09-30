<?php

namespace App\Notifications;

use App\Support\Surface;
use App\Telegram\Actions\UnlinkChat;
use App\Telegram\Bot;
use App\Users\User;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Уведомление в привязанный Telegram: заголовок, строка текста и кнопка-ссылка. Ночью в тихие часы — без звука.
 * Ошибка не выходит наружу: лента и пуш уже доставлены, а упавшая задача подняла бы тревогу check.sh.
 * Заблокировал бота — привязка снимается.
 */
final class TelegramChannel
{
    public function __construct(private Bot $bot) {}

    public function send(User $user, Notice $notice): void
    {
        if (! $user->telegram_chat_id || ! $this->bot->configured()) {
            return;
        }
        $text = '<b>'.e($notice->title()).'</b>'.($notice->text() ? "\n".e($notice->text()) : '');
        $href = $notice->href();
        $url = str_starts_with($href, 'http') ? $href : Surface::Site->url($href);
        $label = str_contains($href, '/deals/') ? 'Открыть сделку' : 'Открыть';
        try {
            $this->bot->send((int) $user->telegram_chat_id, $text, [[['text' => $label, 'url' => $url]]], $user->quietHours());
        } catch (Throwable $e) {
            if (Bot::chatGone($e)) {
                app(UnlinkChat::class)($user);

                return;
            }
            Log::warning('Telegram: уведомление не ушло', ['user' => $user->id, 'title' => $notice->title(), 'error' => $e->getMessage()]);
        }
    }
}
