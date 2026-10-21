<?php

namespace App\Notifications;

use App\Telegram\Actions\UnlinkChat;
use App\Support\Surface;
use App\Telegram\Bot;
use App\Users\User;
use App\Users\Wording;
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

    /**
     * Кнопка на сайт — входом через Telegram (`login_url` → `/telegram/go`, `TelegramEntry`): браузер Telegram открывает
     * её сразу вошедшим. CRM, парковка и логистика — простой ссылкой: домен у бота один.
     */
    public static function button(string $text, string $url): array
    {
        $site = Surface::Site->url('/');
        if (! config('xcar.telegram.login') || ! str_starts_with($url, rtrim($site, '/').'/')) {
            return ['text' => $text, 'url' => $url];
        }
        $path = substr($url, strlen(rtrim($site, '/'))) ?: '/';

        return ['text' => $text, 'login_url' => ['url' => Surface::Site->url('/telegram/go?to='.rawurlencode($path)), 'request_write_access' => false]];
    }

    public function send(User $user, Notice $notice): void
    {
        $message = $notice->toTelegram();
        if (! $user->telegram_chat_id || ! $message || ! $this->bot->configured()) {
            return;
        }
        $lines = array_filter($message['lines'], fn ($l) => $l !== null && $l !== '');
        // Шаг сделки супер-покупателю — без слова «менеджер» (`Users\Wording`); готовый HTML строки — как есть.
        $say = fn ($l) => $l instanceof \Illuminate\Support\HtmlString || ! $notice->speaksRoute() ? $l : Wording::for($user, (string) $l);
        $text = implode("\n", ['<b>'.e($say($message['title'])).'</b>', ...array_map(fn ($l) => e($say($l)), $lines)]);
        try {
            $sent = $this->bot->send((int) $user->telegram_chat_id, $text, [[self::button($message['button'], $notice->telegramUrl())]], $user->quietHours());
            // О чём сообщение — чтобы удалить его у всех, когда предмет ушёл (завели цепочку «Из писем»).
            if ($sent && ($subject = $notice->telegramSubject())) {
                $sent->update(['subject' => $subject]);
            }
        } catch (Throwable $e) {
            if (Bot::chatGone($e)) {
                app(UnlinkChat::class)->byChat((int) $user->telegram_chat_id);

                return;
            }
            Log::warning('Telegram: уведомление не ушло', ['user' => $user->id, 'title' => $notice->title(), 'error' => $e->getMessage()]);
        }
    }
}
