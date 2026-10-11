<?php

namespace App\Offers\Listeners;

use App\Mail\Direction;
use App\Mail\Events\MessageSent;
use App\Mail\Scope;
use App\Offers\OfferEventType;
use App\Workflow\Track;

/**
 * Письмо поставщику ушло (11.10.2026, начальник: «не понимаю, мы реально отправили письмо и ждём или ещё не отправили»):
 * наше письмо в ветке предложения с ящика продаж, а сделка стоит на этапе с письмом (`Stage::isLetterStep`) и ждёт нас —
 * ход переходит поставщику, сутки на ответ считаются от письма, в «Истории» — «Письмо поставщику отправлено».
 */
final class MarkSupplierLetter
{
    public function handle(MessageSent $e): void
    {
        $message = $e->message->loadMissing(['account', 'thread.offer.deal']);
        $offer = $message->thread?->offer;
        if ($message->direction !== Direction::Out || $message->account?->scope !== Scope::Offers || ! $offer?->deal?->isActive()) {
            return;
        }
        $position = $offer->unsetRelation('positions')->position(Track::Sale);
        if (! $position || $position->letterStatus() !== 'unsent') {
            return;
        }
        $at = $message->sent_at ?? $message->date_at ?? now();
        $position->update([
            'waits_for' => null,
            'deadline_at' => $position->stage->deadlineFor($offer, $at->copy()),
            'reminded_at' => null,
            'overdue_at' => null,
            'payload' => [...($position->payload ?? []), 'letter_id' => $message->id, 'letter_at' => $at->toIso8601String()],
        ]);
        $offer->log(OfferEventType::SupplierLetter, $message->author, ['letter' => $message->id]);
    }
}
