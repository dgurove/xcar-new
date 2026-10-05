<?php

namespace App\Http\Admin;

use App\Garage\GaragePayer;
use App\Offers\Actions\AcceptBid;
use App\Offers\Actions\AssignPickup;
use App\Offers\Actions\DeclineBid;
use App\Offers\Actions\PlaceBidFor;
use App\Offers\Bid;
use App\Offers\BidKind;
use App\Offers\Offer;
use App\Users\User;
use App\Offers\CommissionMode;
use App\Offers\Destination;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class BidController
{
    /**
     * Принять подтверждение вместе с деньгами: вознаграждение менеджеру суммой и режим. Гаражное — без денег, с тем,
     * кто платит поставщику: вознаграждение назначают, когда машину продадут из гаража.
     */
    public function accept(Request $request, Bid $bid, AcceptBid $accept)
    {
        $switch = $bid->offer->deal()->exists();
        if ($bid->isGarage()) {
            $data = $request->validate(['payer' => ['nullable', Rule::enum(GaragePayer::class)]]);
            $accept($bid, $request->user(), payer: GaragePayer::tryFrom($data['payer'] ?? '') ?? GaragePayer::Us);

            return back()->with('toast', ($switch ? 'Отдали ' : 'В гараж — ').$bid->user->shortName());
        }
        $data = $request->validate([
            'commission' => ['nullable', 'integer', 'min:0', 'max:'.$bid->amount],
            'mode' => ['nullable', Rule::enum(CommissionMode::class)],
            'pickup' => ['nullable', Rule::in(['manager', 'us'])],
        ]);
        DB::transaction(function () use ($bid, $data, $request, $accept) {
            $offer = $bid->offer->loadMissing('vendor.workflows', 'parkVehicle');
            $choosable = isset($data['pickup']) && $offer->pickupChoosable();
            // Отдаём другому, а забирать должен был прежний: отмена сделки поручение снимет, «Мы» вернёт вывоз на парковку.
            $wasPicker = $offer->deal && $offer->evacuator_id === $offer->deal->buyer_id && $offer->pickupDestination() === Destination::Keeper;
            $accept($bid, $request->user(), isset($data['commission']) ? (int) $data['commission'] : null, CommissionMode::tryFrom($data['mode'] ?? '') ?? CommissionMode::Payout);
            if (! $choosable) {
                return;
            }
            // Кто забирает ТС у владельца (04.10.2026): менеджер сделки — он и вывозчик; мы — как назначено.
            $offer = $offer->fresh(['vendor.workflows', 'positions.stage.workflow', 'parkVehicle.requests']);
            if ($data['pickup'] === 'manager') {
                app(AssignPickup::class)($offer, $bid->user, Destination::Keeper, $request->user());
            } elseif ($wasPicker) {
                app(AssignPickup::class)($offer, null, Destination::Yard, $request->user());
            }
        });

        return back()->with('toast', $switch ? 'Сделка передана '.$bid->user->shortName() : 'Подтверждение принято, сделка открыта');
    }

    /** Подтверждение за менеджера (`PlaceBidFor`): менеджер без интернета — встаёт в список, принимают обычным «Принять». */
    public function storeFor(Request $request, Offer $offer, PlaceBidFor $place)
    {
        $data = $request->validate([
            'manager_id' => ['required', 'integer', 'exists:users,id'],
            'kind' => ['nullable', Rule::enum(BidKind::class)],
            'amount' => ['nullable', 'string', 'max:20'],
            'comment' => ['nullable', 'string', 'max:300'],
        ]);
        $manager = User::findOrFail($data['manager_id']);
        $amount = (int) preg_replace('/\D+/', '', (string) ($data['amount'] ?? '')) ?: null;
        $place($offer, $manager, $request->user(), $amount, BidKind::tryFrom($data['kind'] ?? '') ?? BidKind::Buyer, trim((string) ($data['comment'] ?? '')) ?: null);

        return back()->with('toast', 'Подтверждение '.$manager->shortName().' внесено');
    }

    public function decline(Request $request, Bid $bid, DeclineBid $decline)
    {
        $decline($bid, $request->user());

        return back()->with('toast', 'Подтверждение отклонено');
    }
}
