<?php

namespace App\Logistics\Actions;

use App\Billing\Actions\VoidInvoice;
use App\Billing\InvoiceState;
use App\Logistics\Channel;
use App\Logistics\Events\TransportMoved;
use App\Logistics\Listeners\NotifyLogistics;
use App\Logistics\Transport;
use App\Logistics\TransportEvent;
use App\Logistics\TransportNotice;
use App\Logistics\TransportState;
use App\Offers\Offer;
use App\Users\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Машину забрал покупатель аукциона (Совкомбанк, 20.10.2026, владелец: «покупателя нашли ещё до того, как мы забрали,
 * поэтому забрал не мы, а аукцион»): открытая задача вывоза, что ещё не в пути, — «Забрал аукцион» одним шагом, без
 * исполнителя; неоплаченный долг логисту аннулируется, логист и исполнитель узнают, что ехать не нужно. Уже везут —
 * не трогаем: довезут, покупатель заберёт у нас. Зовёт только `SellAtAuction`.
 */
final class AuctionPickup
{
    public function __construct(private VoidInvoice $void) {}

    public function __invoke(Offer $offer, User $by, Carbon $at): ?Transport
    {
        $t = Transport::openFor($offer);
        if (! $t || ! $t->isPickup() || $t->state->picked()) {
            return null;
        }
        $workers = NotifyLogistics::workers($t, $by);
        // Что было до «Забрал аукцион» — отмена продажи (`UndoAuctionSale`) вернёт задачу как была.
        $was = ['channel' => $t->channel?->value, 'state' => $t->state->value, 'executor_id' => $t->executor_id, 'logist_id' => $t->logist_id,
            'cost' => $t->cost, 'distance_km' => $t->distance_km, 'docs_by_cdek' => (bool) $t->docs_by_cdek, 'assigned_at' => $t->assigned_at?->toIso8601String()];
        DB::transaction(function () use ($t, $by, $at, $was) {
            if ($t->invoice && $t->invoice->state === InvoiceState::Issued && (float) $t->invoice->paid === 0.0) {
                ($this->void)($t->invoice, $by, 'Забрал аукцион');
            }
            $t->update(['channel' => Channel::Auction, 'executor_id' => null, 'logist_id' => null, 'cost' => null, 'distance_km' => null,
                'docs_by_cdek' => false, 'state' => TransportState::Delivered, 'assigned_at' => $t->assigned_at ?? $at,
                'picked_at' => $at, 'delivered_at' => $at]);
            TransportEvent::record($t, 'auction', $by, ['was' => $was]);
        });
        // После коммита продажи: откатилась — исполнитель не должен получить «Вывоз не нужен».
        DB::afterCommit(fn () => Notification::send($workers, TransportNotice::auctionYours($t)));
        TransportMoved::dispatch($t, $by, 'auction');

        return $t;
    }
}
