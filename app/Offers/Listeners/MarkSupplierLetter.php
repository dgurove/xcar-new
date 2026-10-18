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
        $payload = array_diff_key($position->payload ?? [], array_flip(['letter_failed', 'letter_bounced', 'reply_at']));
        $position->update([
            'waits_for' => null,
            'deadline_at' => $position->stage->deadlineFor($offer, $at->copy()),
            'reminded_at' => null,
            'overdue_at' => null,
            'payload' => [...$payload, 'letter_id' => $message->id, 'letter_at' => $at->toIso8601String()],
        ]);
        $offer->log(OfferEventType::SupplierLetter, $message->author, ['letter' => $message->id]);
    }

    public function failed(MessageFailed $e): void
    {
        $position = self::position($e->message);
        if (! $position || ! in_array($position->letterStatus(), ['unsent', 'failed', 'bounced'], true)) {
            return;
        }
        $position->update(['payload' => [...($position->payload ?? []), 'letter_failed' => ['id' => $e->message->id, 'error' => self::reason((string) $e->message->send_error)]]]);
        $e->message->author?->notify(new SupplierLetterNotice($position->offer, 'failed'));
    }

    /** Отказ почтового сервера в ветке письма, что ушло этому этапу. */
    public function bounced(MessageParsed $e): void
    {
        $m = $e->message;
        if ($m->direction !== Direction::In || $m->intent !== Intent::Bounce->value) {
            return;
        }
        $m->loadMissing('thread.offer');
        $offer = $m->thread?->offer;
        $position = $offer?->deal?->isActive() ? $offer->unsetRelation('positions')->position(Track::Sale) : null;
        $letter = $position && isset($position->payload['letter_id']) ? Message::find($position->payload['letter_id']) : null;
        if (! $letter || $letter->thread_id !== $m->thread_id || isset($position->payload['reply_at'])) {
            return;
        }
        $position->update(['waits_for' => WaitsFor::Us, 'payload' => [...$position->payload, 'letter_bounced' => ['at' => now()->toIso8601String(), 'to' => $letter->to_preview]]]);
        $letter->author?->notify(new SupplierLetterNotice($offer, 'bounced'));
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
