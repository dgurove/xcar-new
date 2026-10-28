<?php

namespace App\Offers\Actions;

use App\Logistics\Actions\Cancel;
use App\Logistics\Transport;
use App\Notifications\SupplierDeclinedNotice;
use App\Offers\BidState;
use App\Offers\Deal;
use App\Offers\OfferEventType;
use App\Offers\OfferState;
use App\Users\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Поставщик отказал продавать (28.10.2026, владелец про 812865/2026: Совкомбанк нашёл покупателя сам — «не в архив, а как
 * завершённое по такой-то причине»). Сделка закрывается отменой (`CancelDeal` через `ChangeOfferState`: счета гаснут,
 * просьбы снимаются, выдача с парковки тоже) с причиной `close_reason = supplier`; предложение — в архив «Отказ
 * поставщика», резерв отклоняется молча. Вывоз остаётся (у Совкомбанка он обязателен по контракту, покупатель поставщика
 * забирает машину у нас), $dropPickup — не нужен: ещё не забранная задача отменяется. Менеджеру — громко: иначе он поедет
 * забирать.
 */
final class DeclineBySupplier
{
    public function __construct(private ChangeOfferState $changeState) {}

    public function __invoke(Deal $deal, string $note, ?User $by, bool $dropPickup = false): Deal
    {
        $note = trim($note);
        if (! $deal->isActive()) {
            throw ValidationException::withMessages(['note' => 'Сделка уже закрыта']);
        }
        if ($note === '') {
            throw ValidationException::withMessages(['note' => 'Напишите причину']);
        }
        $offer = $deal->offer;

        $deal = DB::transaction(function () use ($deal, $offer, $note, $by, $dropPickup) {
            // Этап маршрута «Отказ поставщика», если он есть у текущего шага (Альфа, Каркаде), — сам `ChangeOfferState`.
            ($this->changeState)($offer, OfferState::Cancelled, $by);
            $deal = $deal->fresh();
            $deal->update(['close_reason' => 'supplier', 'close_note' => mb_substr($note, 0, 500)]);
            $offer->bids()->where('state', BidState::Active)->update(['state' => BidState::Declined, 'decided_at' => now(), 'decided_by' => $by?->id]);
            $dropped = false;
            $open = Transport::openFor($offer->fresh());
            if ($dropPickup && $open && ! $open->taken()) {
                app(Cancel::class)($open, $by, 'Поставщик отказал: вывоз не нужен', system: true);
                $dropped = true;
            }
            $offer->log(OfferEventType::SupplierDeclined, $by, ['deal' => $deal->id, 'note' => $note, ...($dropped ? ['pickup_cancelled' => true] : [])]);

            return $deal;
        });
        if ($deal->buyer) {
            $deal->buyer->notify(new SupplierDeclinedNotice($deal));
        }

        return $deal;
    }

    /** Этап маршрута «Отказ поставщика» (`EnterStage`): та же отметка, причина — поле шага, если есть. */
    public static function mark(Deal $deal, ?string $note): void
    {
        if (! $deal->close_reason) {
            // Голое имя исхода («Поставщик отказал») причиной не пишется: «Отказ поставщика: поставщик отказал» — повтор.
            $note = $note && mb_strtolower(trim($note)) !== 'поставщик отказал' ? $note : null;
            $deal->update(['close_reason' => 'supplier', 'close_note' => $note]);
        }
    }
}
