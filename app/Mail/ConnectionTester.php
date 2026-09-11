<?php

namespace App\Mail;

use Symfony\Component\Mailer\Transport\Smtp\SmtpTransport;
use Throwable;

/** Приём и вход на SMTP проверяются порознь; таймаут называет вероятную причину — закрытый порт. */
final class ConnectionTester
{
    public function imap(Account $account): array
    {
        $imap = new Imap($account, 15);
        try {
            $folders = array_map(fn ($f) => sprintf('%s — %d', $f['name'], $f['messages'] ?? 0), $imap->folders());

            return ['ok' => true, 'message' => 'Папки: '.implode(', ', $folders)];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        } finally {
            $imap->disconnect();
        }
    }

    public function smtp(Account $account): array
    {
        try {
            $transport = Sender::transport($account);
            if (! $transport instanceof SmtpTransport) {
                return ['ok' => true, 'message' => 'Отправщик не SMTP'];
            }
            $transport->start();
            $transport->stop();

            return ['ok' => true, 'message' => "{$account->smtp_host}:{$account->smtp_port} — вход принят"];
        } catch (Throwable $e) {
            $error = $e->getMessage();
            $blocked = str_contains($error, 'timed out') || str_contains($error, 'could not be established') || str_contains($error, 'refused');

            return ['ok' => false, 'message' => $blocked ? "{$error} — похоже, порт {$account->smtp_port} наружу закрыт; mail.ru принимает и на 2525 со STARTTLS" : $error];
        }
    }
}
