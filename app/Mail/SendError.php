<?php

namespace App\Mail;

/**
 * Почему письмо не ушло — словами над кнопкой «Отправить» (`compose_send_controller`, 15.11.2026): ответ почтового сервера
 * («Expected response code 250 but got 550 …») начальнику ничего не говорит. Частые случаи — фразой, своё по-русски
 * (прервалась на выкладке) — как есть, остальное — общей фразой; ответ сервера целиком остаётся в `send_error`.
 */
final class SendError
{
    public static function words(?string $error): ?string
    {
        if ($error === null || trim($error) === '') {
            return null;
        }
        $line = trim((string) strtok($error, "\n"));

        return match (true) {
            (bool) preg_match('/\b(550|551|553)\b|mailbox (unavailable|not found)|user unknown|no such user/i', $line) => 'Адрес получателя не существует или закрыт, проверьте «Кому»',
            (bool) preg_match('/\b552\b|too large|size limit/i', $line) => 'Письмо слишком большое, уберите часть вложений',
            (bool) preg_match('/\b535\b|authenticat|password/i', $line) => 'Почта не приняла пароль ящика, напишите владельцу',
            (bool) preg_match('/timed? ?out|connection (refused|could not)|unable to connect|getaddrinfo/i', $line) => 'Нет связи с почтовым сервером, попробуйте снова',
            (bool) preg_match('/прервалась/u', $line) => $line,
            (bool) preg_match('/\p{Cyrillic}/u', $line) => mb_substr($line, 0, 200),
            default => 'Письмо не ушло, попробуйте ещё раз',
        };
    }
}
