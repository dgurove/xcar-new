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

    public function send(User $user, Notice $notice): Sent
    {
        $message = $notice->toTelegram();
        if (! $user->telegram_chat_id || ! $message || ! $this->bot->configured()) {
            return Sent::skipped('Telegram не привязан');
        }
        $lines = array_filter($message['lines'], fn ($l) => $l !== null && $l !== '');
        // Шаг сделки супер-покупателю — без слова «менеджер» (`Users\Wording`); готовый HTML строки — как есть.
        $say = fn ($l) => $l instanceof \Illuminate\Support\HtmlString || ! $notice->speaksRoute() ? $l : Wording::for($user, (string) $l);
        $text = implode("\n", ['<b>'.e($say($message['title'])).'</b>', ...array_map(fn ($l) => e($say($l)), $lines)]);
        try {
            // Свои кнопки ответа (`keyboard`, контрпредложение: «Согласен» / «Отказаться») — над кнопкой на сайт.
            $sent = $this->bot->send((int) $user->telegram_chat_id, $text, [...($message['keyboard'] ?? []), [self::button($message['button'], NoticeLink::tracked($notice->telegramUrl(), $notice, 'telegram'))]], $user->quietHours());
            // О чём сообщение — чтобы удалить его у всех, когда предмет ушёл (завели цепочку «Из писем»).
            if ($sent && ($subject = $notice->telegramSubject())) {
                $sent->update(['subject' => $subject]);
            }
        } catch (Throwable $e) {
            if (Bot::chatGone($e)) {
                app(UnlinkChat::class)->byChat((int) $user->telegram_chat_id);

                return Sent::failed('чат удалён, Telegram отвязан');
            }
            Log::warning('Telegram: уведомление не ушло', ['user' => $user->id, 'title' => $notice->title(), 'error' => $e->getMessage()]);
            // Сеть до отправки, 429, 5xx — повтор заданием через 1, 5, 15 минут (`RetryDelivery`); ответ, потерянный после
            // отправки (таймаут чтения), не повторяем: сообщение могло уйти, второе было бы дублем.
            if ($notice->id && self::transient($e)) {
                throw RetryDelivery::after($notice, $user->id, 'telegram', mb_substr($e->getMessage(), 0, 120));
            }

            return Sent::failed($e->getMessage());
        }

        // Файлы сообщения чата — вслед за текстом, ответом на него (`ChatNotice::telegramFiles`, 16.11.2026). Не ушёл файл — в лог,
        // текст уже доставлен.
        if ($sent && method_exists($notice, 'telegramFiles')) {
            foreach ($notice->telegramFiles() as $file) {
                $this->sendFile($user, $file, (int) $sent->message_id, $notice->telegramSubject());
            }
        }

        return $sent ? Sent::ok(retries: RetryDelivery::retries()) : Sent::failed('Telegram не ответил');
    }

    /** Сбой, после которого сообщение точно не ушло: DNS, соединение, 429, ошибка сервера Telegram. */
    public static function transient(Throwable $e): bool
    {
        if ($e instanceof \Illuminate\Http\Client\RequestException) {
            $status = $e->response->status();

            return $status === 429 || $status >= 500;
        }
        if ($e instanceof \Illuminate\Http\Client\ConnectionException) {
            return preg_match('/resolv|Could not resolve|Failed to connect|Connection timed out|Connection refused|путей нет|SSL connect/i', $e->getMessage()) === 1;
        }

        return false;
    }

    private function sendFile(User $user, \App\Chats\File $file, int $replyTo, ?string $subject = null): void
    {
        try {
            $disk = \Illuminate\Support\Facades\Storage::disk('private');
            if (! $disk->exists($file->path)) {
                return;
            }
            $upload = new \Illuminate\Http\UploadedFile($disk->path($file->path), $file->name ?: basename($file->path), $file->mime, null, true);
            $sent = $this->bot->sendFile((int) $user->telegram_chat_id, $upload, null, $replyTo ?: null);
            // Тот же предмет, что у текста: удалили сообщение чата — уходят и файлы (`RetractChatMessage`).
            $sent && $subject && $sent->update(['subject' => $subject]);
        } catch (Throwable $e) {
            Log::warning('Telegram: файл чата не ушёл', ['user' => $user->id, 'file' => $file->id, 'error' => $e->getMessage()]);
        }
    }
}
