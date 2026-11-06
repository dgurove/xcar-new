<?php

namespace App\Mail;

use App\Mail\Jobs\SendSystemMail;
use App\Notifications\Sent;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Throwable;

/**
 * Системные письма — одна дверь (06.11.2026, владелец: «no_reply@xcar.ru для СУПЕР важного, все ненавидят спам»): номера
 * «К запросу» сотруднику, «Забыл пароль», ссылка и данные входа, подтверждение почты, письма безопасности и уведомления,
 * которые человек сам включил на почту. Отправитель — SMTP no-reply из `.env` (`MAIL_MAILER=smtp`); пока его нет, на
 * проде — ящик `xcar.system_account` (deal@), вне прода — никуда (журнал): в локальной копии базы у ящиков настоящие пароли.
 * Каждое письмо — с текстовой частью, `Auto-Submitted`, логотипом по Content-ID, у уведомлений — `List-Unsubscribe`.
 */
final class SystemMail
{
    /**
     * Письмо из вида `emails.*` (на общем `emails.layout`).
     *
     * @param  array{text?: string, unsubscribe?: string}  $opts
     */
    public static function make(string $to, ?string $name, string $subject, string $html, array $opts = []): Email
    {
        $email = (new Email)->to(new Address($to, (string) $name))->subject($subject)->html($html)
            ->text($opts['text'] ?? app(Parser::class)->htmlToText($html));
        $email->embedFromPath(public_path('images/xcar-mail.png'), 'logo', 'image/png');
        $headers = $email->getHeaders();
        $headers->addTextHeader('Auto-Submitted', 'auto-generated');
        if (! empty($opts['unsubscribe'])) {
            $headers->addTextHeader('List-Unsubscribe', '<'.$opts['unsubscribe'].'>');
            $headers->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
        }

        return $email;
    }

    /** Через что слать и от кого. null — слать нечем (вне прода без SMTP: в журнал). @return array{0: TransportInterface, 1: Address}|null */
    public static function sender(): ?array
    {
        $mailer = config('mail.default');
        if ($mailer !== 'log') {
            return [Mail::mailer($mailer)->getSymfonyTransport(), new Address((string) config('mail.from.address'), (string) config('mail.from.name'))];
        }
        if (! app()->isProduction()) {
            return null;
        }
        $account = Account::where('slug', config('xcar.system_account'))->where('is_active', true)->first()
            ?? Account::where('scope', Scope::Offers)->where('is_active', true)->orderBy('id')->first();

        return $account ? [Sender::transport($account), new Address($account->email, 'XCar')] : null;
    }

    /** Отправить сейчас (уведомление — уже в очереди): итог — в журнал доставки. */
    public static function sendNow(Email $email): Sent
    {
        $sender = self::sender();
        if (! $sender) {
            Log::info('Системное письмо: отправлять нечем, только в журнал', ['to' => $email->getTo()[0]?->getAddress(), 'subject' => $email->getSubject()]);

            return Sent::skipped('почта не настроена');
        }
        [$transport, $from] = $sender;
        try {
            $transport->send($email->from($from));
        } catch (Throwable $e) {
            Log::warning('Системное письмо не ушло: '.$e->getMessage(), ['to' => $email->getTo()[0]?->getAddress(), 'subject' => $email->getSubject()]);

            return Sent::failed($e->getMessage());
        }

        return Sent::ok();
    }

    /**
     * В очередь с повторами (1, 5 и 15 минут) — для писем, которые обязаны дойти (номера Андрею). $meta — что делать,
     * если не ушло и после повторов (`SendSystemMail::failed`).
     */
    public static function queue(Email $email, array $meta = []): void
    {
        SendSystemMail::dispatch(serialize($email), $meta);
    }
}
