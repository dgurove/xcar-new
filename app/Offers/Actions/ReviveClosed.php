<?php

namespace App\Offers\Actions;

use App\Mail\Direction;
use App\Mail\Extraction\Intent;
use App\Mail\Message;
use App\Offers\Offer;
use App\Offers\OfferEventType;
use App\Support\Nav;
use Illuminate\Support\Facades\DB;

/**
 * Письмо поставщика возвращает машину из «Без подтверждений» в «Без продажной цены» (18.11.2026, владелец по AUT-26-1013306:
 * приём закрылся без подтверждений, через 9 дней Т-Страхование прислало «Предлагаем принять участие в оценке ТС» — письмо легло к
 * машине, а она лежала в «Без подтверждений», и его никто не увидел; «любое новое письмо по тачке должно её воскрешать»).
 * Цена продажи снимается (было → стало в «Истории»), метка закрытого приёма гаснет, во вкладку — сейчас: машина первой в
 * «Без продажной цены». Что было — событие `Revived` (цена, сколько подтверждений, когда закрыт приём, письмо): строкой у «Цен»
 * (`Offer::revival`). Возвращает только входящее письмо поставщика (или пересылка сотрудника) позже закрытия приёма.
 */
final class ReviveClosed
{
    public function __construct(private UpdateOffer $update) {}

    /** Новое письмо по машине (`OnMessage`). */
    public static function onLetter(Offer $offer, Message $message): void
    {
        if (self::wakes($offer, $message)) {
            app(self::class)($offer, $message);
        }
    }

    /** Будит ли это письмо машину. */
    public static function wakes(Offer $offer, Message $message): bool
    {
        return ReturnToSale::unsold($offer) && $message->direction === Direction::In
            && ($message->isFromVendor() || $message->isForwardedByStaff()) && ! Intent::silent($message->intent)
            && $message->date_at && $message->date_at->gt($offer->intake_closed_at);
    }

    public function __invoke(Offer $offer, Message $message): ?Offer
    {
        $offer = DB::transaction(function () use ($offer, $message) {
            $offer = Offer::lockForUpdate()->find($offer->id);
            if (! $offer || ! ReturnToSale::unsold($offer)) {
                return null; // другое письмо уже вернуло или машину успели тронуть
            }
            $asking = (int) $offer->asking_price;
            $closed = $offer->intake_closed_at;
            // Подтверждения последнего показа — в любом состоянии (отозванные и отклонённые тоже были).
            $bids = $offer->bids()->when($offer->published_at, fn ($b, $at) => $b->where('created_at', '>=', $at))->count();
            if ($asking) {
                $offer = ($this->update)($offer, ['asking_price' => null], null, ['why' => 'Письмо поставщика после закрытия приёма', 'letter' => $message->id]);
            }
            DB::table('offers')->where('id', $offer->id)->update(['intake_closed_at' => null, 'tab_at' => now(), 'tab_by' => null]);
            $offer->log(OfferEventType::Revived, null, ['asking' => $asking, 'bids' => $bids, 'closed_at' => $closed?->toIso8601String(), 'letter' => $message->id]);

            return $offer->refresh();
        });
        Nav::forgetStaffCounts();

        return $offer;
    }
}
