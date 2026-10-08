<?php

namespace App\Http\Site;

use App\Offers\Actions\PlaceBid;
use App\Offers\Actions\PlaceBidFor;
use App\Offers\Actions\WithdrawBid;
use App\Offers\Bid;
use App\Offers\BidKind;
use App\Offers\Offer;
use App\Users\Role;
use App\Users\User;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class BidController
{
    public function store(Request $request, Offer $offer, PlaceBid $place, PlaceBidFor $placeFor)
    {
        $me = $request->user();
        $kind = BidKind::tryFrom((string) $request->input('kind')) ?? BidKind::Buyer;
        // Админ подтверждает за менеджера (08.10.2026): та же форма, в шторке — за кого.
        $staff = $me->isAdmin() && ! $me->isManager();
        $data = $request->validate([
            'amount' => [$kind === BidKind::Buyer ? 'required' : 'nullable', 'string'],
            'comment' => ['nullable', 'string', 'max:500'],
            'manager_id' => [$staff ? 'required' : 'nullable', 'integer'],
        ], ['manager_id.required' => 'Выберите менеджера']);
        $amount = (int) preg_replace('/\D+/', '', (string) ($data['amount'] ?? '')) ?: null;

        if (! $staff) {
            $place($offer, $me, $amount, $data['comment'] ?? null, $kind);

            return back()->with('toast', $kind === BidKind::Garage ? 'В гараж — ждёт решения' : 'Подтверждение отправлено');
        }

        $manager = User::withRole(Role::Manager)->find($data['manager_id']);
        if (! $manager) {
            throw ValidationException::withMessages(['manager_id' => 'Выберите менеджера']);
        }
        // Как у менеджера: приём открыт или идёт сделка, цена не ниже порога. `PlaceBidFor` их не проверяет — он для CRM.
        if (! $offer->bidsOpen() && ! $offer->canBidInDeal($me)) {
            throw ValidationException::withMessages(['amount' => 'Приём подтверждений закрыт']);
        }
        $min = $offer->minBid();
        if ($kind === BidKind::Buyer && $min && $amount && $amount < $min) {
            throw ValidationException::withMessages(['amount' => 'Такую цену не принимаем — предложите выше']);
        }
        $placeFor($offer, $manager, $me, $amount, $kind, $data['comment'] ?? null);

        return back()->with('toast', ($kind === BidKind::Garage ? 'В гараж за ' : 'Подтверждение за ').$manager->shortName().' внесено');
    }

    public function withdraw(Request $request, Bid $bid, WithdrawBid $withdraw)
    {
        abort_unless($bid->user_id === $request->user()->id, 403);
        $withdraw($bid, $request->user());

        return back()->with('toast', 'Подтверждение отозвано');
    }
}
