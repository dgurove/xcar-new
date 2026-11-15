<?php

namespace App\Mail;

/**
 * Почему письмо не ушло — словами для экрана отправки (`admin/mail/sent-frame`, 15.11.2026): ответ почтового сервера
 * («Expected response code 250 but got 550 …») начальнику ничего не говорит. Частые случаи — фразой, остальное — первой
 * строкой ответа как есть.
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
            default => mb_substr(preg_replace('/^Expected response code \S+ but got (code )?"?\d+"?,?( with message ")?/u', '', $line), 0, 200),
        };
    }
}
