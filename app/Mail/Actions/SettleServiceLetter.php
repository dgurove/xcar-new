<?php

namespace App\Mail\Actions;

use App\Mail\Direction;
use App\Mail\Extraction\Intent;
use App\Mail\Message;
use App\Mail\Scope;
use App\Offers\Listeners\MarkSupplierLetter;
use App\Telegram\Jobs\NotifyOwner;
use App\Telegram\Messages\MailServiceNotice;
use Illuminate\Support\Str;

/**
 * Служебное письмо ящика предложений без дела — сразу в архив (04.11.2026, владелец: «технические письма от mail ru
 * daemon — придумать, что с ними делать»): рассылки и вход в аккаунт почты (`Intent::Auto`), отказ сервера на письмо,
 * которого в ящике нет — уведомление системы человеку (13.10.2026 Яндекс отбил «XCar: ответ на …» как спам). Отказ на
 * наше письмо поставщику — как раньше: шаг сделки краснеет (`MarkSupplierLetter`), ветка ждёт ответа. Что нужно знать
 * владельцу — недоставка и «Подтвердите пересылку» с кодом — ему в Telegram.
 */
final class SettleServiceLetter
{
    public function __construct(private ArchiveThread $archive) {}

    public function __invoke(Message $message): void
    {
        $thread = $message->thread;
        if (! $thread || $message->direction !== Direction::In || $message->account?->scope !== Scope::Offers
            || $thread->offer_id || $thread->vehicle_id || $thread->candidate_id || ! Intent::silent($message->intent)) {
            return;
        }
        $text = $message->text_body ?: strip_tags((string) $message->html_body);
        if ($message->intent === Intent::Bounce->value) {
            if (MarkSupplierLetter::bouncedLetter($message)) {
                return;
            }
            $to = Intent::bounceRecipient($text);
            NotifyOwner::dispatch(new MailServiceNotice('Письмо не дошло', [
                'Кому: '.($to ?? 'адрес не понятен'),
                self::reason($text),
                'Письмо системы — уведомление человеку, в почте его нет',
            ], $thread->id));
        } elseif (preg_match('/подтвердите\s+пересылку/iu', (string) $message->subject.' '.$text)) {
            preg_match('/https:\/\/e\.mail\.ru\/confirm\/forward\S+/u', html_entity_decode($text), $link);
            NotifyOwner::dispatch(new MailServiceNotice('Почта просит подтвердить пересылку на '.$message->account->email, [
                Str::limit(trim((string) preg_replace('/\s+/u', ' ', $text)), 200),
                $link[0] ?? null,
            ], $thread->id));
        }
        ($this->archive)($thread);
    }

    /** Причина отказа словами сервера: строка с кодом 5xx или «rejected». */
    private static function reason(string $text): string
    {
        return preg_match('/^.*(?:\b5\d\d\b|rejected|не\s+существует|does\s+not\s+exist).*$/imu', $text, $m) ? Str::limit(trim($m[0]), 160) : 'Причину сервер не назвал';
    }
}
