<?php

namespace App\Offers\Listeners;

use App\Mail\Direction;
use App\Mail\Events\MessageFailed;
use App\Mail\Events\MessageParsed;
use App\Mail\Events\MessageSent;
use App\Mail\Extraction\Intent;
use App\Mail\Message;
use App\Mail\Scope;
use App\Notifications\SupplierLetterNotice;
use App\Offers\OfferEventType;
use App\Workflow\Position;
use App\Workflow\Track;
use App\Workflow\WaitsFor;
use Illuminate\Events\Dispatcher;

/**
 * Письмо поставщику ушло (11.10.2026, начальник: «не понимаю, мы реально отправили письмо и ждём или ещё не отправили»):
 * наше письмо в ветке предложения с ящика продаж, а сделка стоит на этапе с письмом (`Stage::isLetterStep`) и ждёт нас —
 * ход переходит поставщику, сутки на ответ считаются от письма, в «Истории» — «Письмо поставщику отправлено».
 *
 * Доставка в шаге (18.10.2026, владелец): не ушло с нашей стороны (`MessageFailed`) — `payload.letter_failed`, шаг красным
 * «Письмо не ушло» с «Повторить»; вернулся отказ почтового сервера (`Intent::Bounce` в ветке ушедшего письма) —
 * `payload.letter_bounced`, красным «Не доставлено» с «Написать снова». Обоим — уведомление тому, кто писал.
 */
final class MarkSupplierLetter
{
    public function subscribe(Dispatcher $events): array
    {
        return [MessageSent::class => 'sent', MessageFailed::class => 'failed', MessageParsed::class => 'bounced'];
    }

    public function sent(MessageSent $e): void
    {
        $position = self::position($e->message);
        if (! $position || ! in_array($position->letterStatus(), ['unsent', 'failed', 'bounced'], true)) {
            return;
        }
        $offer = $position->offer;
        $message = $e->message;
        $at = $message->sent_at ?? $message->date_at ?? now();
        $payload = array_diff_key($position->payload ?? [], array_flip(['letter_failed', 'letter_bounced']));
        $position->update([
            'waits_for' => null,
            'deadline_at' => $position->stage->deadlineFor($offer, $at->copy()),
            'reminded_at' => null,
            'overdue_at' => null,
            'payload' => [...$payload, 'letter_id' => $message->id, 'letter_at' => $at->toIso8601String()],
        ]);
        $offer->log(OfferEventType::SupplierLetter, $message->author, ['letter' => $message->id]);
    }

    /** Не ушло: шаг красным; уведомление — один раз на письмо («Повторить» с тем же сбоем не звенит снова). */
    public function failed(MessageFailed $e): void
    {
        $position = self::position($e->message);
        if (! $position || ! in_array($position->letterStatus(), ['unsent', 'failed', 'bounced'], true)) {
            return;
        }
        $again = ($position->payload['letter_failed']['id'] ?? null) === $e->message->id;
        $position->update(['payload' => [...($position->payload ?? []), 'letter_failed' => ['id' => $e->message->id, 'error' => self::reason((string) $e->message->send_error)]]]);
        if (! $again) {
            $e->message->author?->notify(new SupplierLetterNotice($position->offer, 'failed'));
        }
    }

    /**
     * Отказ почтового сервера на наше письмо этого шага. Отказ приходит своей темой («Undelivered Mail…», «Не доставлено»)
     * и часто своей веткой — письмо ищем по его Message-ID в заголовках и теле отказа, иначе по ветке, иначе по адресату и
     * теме; только отказ новее письма и не из истории ящика (`quiet`, перечитывание) — старый отказ не красит новое письмо.
     */
    public function bounced(MessageParsed $e): void
    {
        $m = $e->message;
        if ($e->quiet || $m->direction !== Direction::In || $m->intent !== Intent::Bounce->value || $m->account?->scope !== Scope::Offers) {
            return;
        }
        $letter = self::bouncedLetter($m);
        $position = $letter ? self::position($letter) : null;
        if (! $position || $position->letterStatus() !== 'sent' || ($position->payload['letter_id'] ?? null) !== $letter->id) {
            return;
        }
        $position->update(['waits_for' => WaitsFor::Us, 'payload' => [...$position->payload, 'letter_bounced' => ['at' => now()->toIso8601String(), 'to' => Intent::bounceRecipient($m->text_body ?: strip_tags((string) $m->html_body)) ?? $letter->to_preview]]]);
        $letter->author?->notify(new SupplierLetterNotice($position->offer, 'bounced'));
    }

    /** Наше письмо, на которое пришёл отказ: за три дня до него, тем же ящиком. */
    private static function bouncedLetter(Message $bounce): ?Message
    {
        $at = $bounce->date_at ?? now();
        $text = implode("\n", [$bounce->in_reply_to, $bounce->references_header, $bounce->text_body, $bounce->html_body]);
        $to = Intent::bounceRecipient($bounce->text_body ?: strip_tags((string) $bounce->html_body));
        $letters = Message::where('account_id', $bounce->account_id)->where('direction', Direction::Out)
            ->whereBetween('date_at', [$at->copy()->subDays(3), $at])->latest('date_at')->get();

        return $letters->first(fn (Message $l) => $l->message_id && str_contains($text, trim($l->message_id, '<>')))
            ?? $letters->first(fn (Message $l) => $l->thread_id === $bounce->thread_id)
            ?? ($to ? $letters->first(fn (Message $l) => str_contains(mb_strtolower((string) $l->to_preview), $to) && $l->subject && str_contains($text, $l->subject)) : null);
    }

    /** Позиция сделки, которой адресовано наше письмо из ветки предложения с ящика продаж. */
    private static function position(Message $message): ?Position
    {
        $message->loadMissing(['account', 'thread.offer.deal']);
        $offer = $message->thread?->offer;
        if ($message->direction !== Direction::Out || $message->account?->scope !== Scope::Offers || ! $offer?->deal?->isActive()) {
            return null;
        }
        $position = $offer->unsetRelation('positions')->position(Track::Sale);
        $position?->setRelation('offer', $offer);

        return $position;
    }

    /** Причина словами: «Mailbox unavailable», «Connection timed out» — первая строка без кодов. */
    private static function reason(string $error): string
    {
        $line = trim((string) strtok($error, "\n"));

        return mb_substr(preg_replace('/^(Expected response code \S+ but got code "\d+", with message ")?/u', '', $line), 0, 160);
    }
}
