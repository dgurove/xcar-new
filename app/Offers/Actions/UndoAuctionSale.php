<?php

namespace App\Offers\Actions;

use App\Billing\Actions\VoidInvoice;
use App\Billing\InvoiceState;
use App\Billing\PaymentState;
use App\Logistics\Channel;
use App\Logistics\Events\TransportMoved;
use App\Logistics\Transport;
use App\Logistics\TransportState;
use App\Offers\CarPlace;
use App\Offers\Deal;
use App\Offers\DealState;
use App\Offers\Offer;
use App\Offers\OfferEventType;
use App\Offers\OfferState;
use App\Park\Actions\MarkSold;
use App\Park\Vehicle;
use App\Park\VehicleState;
use App\Users\User;
use App\Workflow\Actions\SetCarPlace;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * «Отменить продажу на аукционе» (20.10.2026, владелец: нужна): пока по счёту не платили и об оплате не сообщали — счёт
 * аннулируется, сделка отменяется, предложение снова черновик «На аукцион», вывоз «Забрал аукцион» снова ждёт исполнителя,
 * выдача на парковке снимается, место машины — прежнее.
 */
final class UndoAuctionSale
{
    public function __construct(private VoidInvoice $void, private ChangeOfferState $state, private SetCarPlace $place, private MarkSold $markSold) {}

    /** Сделка аукциона этого предложения, которую ещё можно отменить. */
    public static function dealOf(Offer $offer): ?Deal
    {
        if ($offer->state !== OfferState::Delivered) {
            return null;
        }
        $deal = Deal::where('offer_id', $offer->id)->where('state', DealState::Done)->whereNotNull('auction_at')->latest('id')->first();

        return $deal && ! self::paid($deal) ? $deal : null;
    }

    private static function paid(Deal $deal): bool
    {
        return $deal->invoices()->get()->contains(fn ($i) => (float) $i->paid > 0
            || $i->payments()->whereIn('state', [PaymentState::Confirmed, PaymentState::Claimed])->exists());
    }

    public function __invoke(Offer $offer, User $by): Offer
    {
        if (! $by->canManageCrm()) {
            throw ValidationException::withMessages(['offer' => 'Отменяет админ']);
        }

        return DB::transaction(function () use ($offer, $by) {
            $offer = Offer::whereKey($offer->id)->lockForUpdate()->firstOrFail();
            if (! ($deal = self::dealOf($offer))) {
                throw ValidationException::withMessages(['offer' => 'По счёту уже платили: сначала верните оплату']);
            }
            foreach ($deal->invoices()->get() as $invoice) {
                if ($invoice->state === InvoiceState::Issued) {
                    ($this->void)($invoice, $by, 'Продажа на аукционе отменена');
                }
            }
            $deal->update(['state' => DealState::Cancelled, 'closed_at' => now()]);
            $note = $offer->events()->where('type', OfferEventType::Note->value)->whereNotNull('payload->auction')->first();
            $was = $note?->payload['auction'] ?? [];

            $this->state->__invoke($offer, OfferState::Draft, $by, followRoute: false, auction: true);
            $offer = $offer->fresh();
            // Снова «На аукцион»: с прежней даты, если продали с аукциона.
            $offer->forceFill(['auction_at' => isset($was['since']) ? \Illuminate\Support\Carbon::parse($was['since']) : now(), 'auction_by' => $offer->auction_by ?? $by->id])->save();

            // Вывоз, что закрыла продажа, снова ждёт исполнителя.
            $t = Transport::where('car_id', $offer->car_id)->where('channel', Channel::Auction->value)->latest('id')->first();
            if ($t && ! Transport::openFor($offer)) {
                $t->update(['channel' => null, 'state' => TransportState::New, 'assigned_at' => null, 'picked_at' => null, 'delivered_at' => null]);
                TransportMoved::dispatch($t, $by, 'auction_undone');
            }
            // Выдачу покупателю аукциона на парковке — снять.
            if (($id = $was['vehicle'] ?? null) && ($v = Vehicle::find($id)) && $v->state !== VehicleState::Released && $v->sold_at) {
                $this->markSold->clear($v, $by, 'Продажа на аукционе отменена');
            }
            $place = $t ? CarPlace::Owner : (CarPlace::tryFrom((string) ($was['place'] ?? '')) ?? null);
            if ($offer->car_place === CarPlace::Buyer || $t) {
                ($this->place)($offer, $place, $by);
            }

            return $offer->fresh();
        });
    }
}
