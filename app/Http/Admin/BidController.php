<?php

namespace App\Http\Admin;

use App\Garage\GaragePayer;
use App\Offers\Actions\AcceptBid;
use App\Offers\Actions\AssignPickup;
use App\Offers\Actions\DeclineBid;
use App\Offers\Actions\PlaceBidFor;
use App\Offers\Bid;
use App\Offers\BidKind;
use App\Offers\CommissionMode;
use App\Offers\DealScheme;
use App\Offers\Destination;
use App\Offers\Offer;
use App\Users\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class BidController
{
    /**
     * Принять подтверждение вместе с деньгами: кому платят за ТС (нам или страхователю по ДКП — тогда и сколько),
     * вознаграждение менеджеру суммой и режим. Гаражное — без денег, с тем,
     * кто платит поставщику: вознаграждение назначают, когда машину продадут из гаража.
     */
    public function accept(Request $request, Bid $bid, AcceptBid $accept)
    {
        $switch = $bid->offer->deal()->exists();
        $default = DealScheme::forVendor($bid->offer->vendor?->deal_format);
        if ($bid->isGarage()) {
            $request->merge(['share' => preg_replace('/\D+/', '', (string) $request->input('share')) ?: null]);
            $data = $request->validate(['payer' => ['nullable', Rule::enum(GaragePayer::class)], 'pickup' => ['nullable', Rule::in(['manager', 'us'])],
                'share' => ['nullable', 'integer', 'min:1']]);
            DB::transaction(function () use ($bid, $data, $request, $accept, $default) {
                $offer = $bid->offer->loadMissing('vendor.workflows', 'parkVehicle');
                $choosable = $offer->pickupChoosable();
                $accept($bid, $request->user(), payer: GaragePayer::tryFrom($data['payer'] ?? '') ?? GaragePayer::Us, scheme: $default, share: isset($data['share']) ? (int) $data['share'] : null);
                // Машина едет к менеджеру в гараж (05.10.2026, Бородин: был вывоз «к нам»), везёт он сам или мы.
                $offer = $offer->fresh(['vendor.workflows', 'positions.stage.workflow', 'parkVehicle.requests']);
                if ($choosable && $offer->pickupChoosable()) {
                    app(AssignPickup::class)($offer, ($data['pickup'] ?? 'manager') === 'manager' ? $bid->user : null, Destination::Keeper, $request->user());
                }
            });

            return back()->with('toast', ($switch ? 'Отдали ' : 'В гараж — ').$bid->user->shortName());
        }
        $request->merge(['owner_price' => preg_replace('/\D+/', '', (string) $request->input('owner_price')) ?: null]);
        $data = $request->validate([
            'commission' => ['nullable', 'integer', 'min:0', 'max:'.$bid->amount],
            'mode' => ['nullable', Rule::enum(CommissionMode::class)],
            'scheme' => ['nullable', Rule::enum(DealScheme::class)],
            // По ДКП — что покупатель отдаст страхователю: меньше цены подтверждения, иначе подбору не из чего.
            'owner_price' => ['nullable', 'integer', 'min:1', 'max:'.$bid->amount],
            'pickup' => ['nullable', Rule::in(['manager', 'us'])],
        ]);
        $scheme = DealScheme::tryFrom($data['scheme'] ?? '') ?? $default;
        if ($scheme->paysSelection() && ! isset($data['owner_price']) && ! $bid->offer->owner_price && ! $bid->offer->floor_price) {
            return back()->withErrors(['owner_price' => $scheme === DealScheme::OwnerDkp ? 'Сколько покупатель отдаёт страхователю по ДКП' : 'Сколько покупатель отдаёт страховой'])->withInput();
        }
        DB::transaction(function () use ($bid, $data, $request, $accept, $scheme) {
            $offer = $bid->offer->loadMissing('vendor.workflows', 'parkVehicle');
            $choosable = isset($data['pickup']) && $offer->pickupChoosable();
            // Отдаём другому, а забирать должен был прежний: отмена сделки поручение снимет, «Мы» вернёт вывоз на парковку.
            $wasPicker = $offer->deal && $offer->evacuator_id === $offer->deal->buyer_id && $offer->pickupDestination() === Destination::Keeper;
            $accept($bid, $request->user(), isset($data['commission']) ? (int) $data['commission'] : null, CommissionMode::tryFrom($data['mode'] ?? '') ?? CommissionMode::Payout,
                scheme: $scheme, ownerPrice: isset($data['owner_price']) ? (int) $data['owner_price'] : null);
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
