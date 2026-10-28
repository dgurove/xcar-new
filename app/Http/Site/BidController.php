<?php

namespace App\Http\Site;

use App\Offers\Actions\AnswerCounter;
use App\Offers\Actions\PlaceBid;
use App\Offers\Actions\PlaceBidFor;
use App\Offers\Actions\WithdrawBid;
use App\Offers\Bid;
use App\Offers\BidKind;
use App\Offers\Offer;
use App\Support\Money;
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
        $amount = Money::rubles($data['amount'] ?? null) ?: null;

        if (! $staff) {
            $place($offer, $me, $amount, $data['comment'] ?? null, $kind, $request->boolean('low_ok'));

            return back()->with('toast', $kind === BidKind::Garage ? 'В гараж, ждёт решения' : 'Подтверждение отправлено');
        }

        $manager = User::withRole(Role::Manager)->find($data['manager_id']);
        if (! $manager) {
            throw ValidationException::withMessages(['manager_id' => 'Выберите менеджера']);
        }
        // Как у менеджера: приём открыт или идёт сделка, цена по зонам. `PlaceBidFor` их не проверяет — он для CRM.
        if (! $offer->bidsOpen() && ! $offer->canBidInDeal($me)) {
            throw ValidationException::withMessages(['amount' => 'Приём подтверждений закрыт']);
        }
        if ($kind === BidKind::Buyer && $amount) {
            PlaceBid::checkZone($offer, $amount, $request->boolean('low_ok'));
        }
        $placeFor($offer, $manager, $me, $amount, $kind, $data['comment'] ?? null);

        return back()->with('toast', ($kind === BidKind::Garage ? 'В гараж за ' : 'Подтверждение за ').$manager->shortName().' внесено');
    }

    /**
     * Зона цены до отправки (26.10.2026): форма спрашивает при «Подтвердить», а не на лету — порог не нащупать вводом.
     * Только слово зоны, без сумм.
     */
    public function check(Request $request, Offer $offer)
    {
        $amount = Money::rubles($request->input('amount')) ?: 0;
        abort_unless($request->user()->canBid() || $request->user()->isAdmin(), 403);

        return response()->json(['zone' => $amount ? $offer->bidZone($amount) : 'ok']);
    }

    /** Ответ на контрпредложение: «Согласен» — сразу сделка, «Отказаться» — подтверждение по своей цене. */
    public function answerCounter(Request $request, Bid $bid, AnswerCounter $answer)
    {
        $agree = $request->boolean('agree');
        $answer($bid, $request->user(), $agree);

        return $agree ? redirect($bid->offer->deal()->first()?->href() ?? '/offers/'.$bid->offer->number)->with('toast', 'Сделка открыта')
            : back()->with('toast', 'Вы отказались, ваша цена в силе');
    }

    /** Выросла закупочная: «Согласен» — новая цена, «Отказываюсь» — сделка отменена или подтверждение снято. */
    public function answerReprice(Request $request, \App\Offers\RepriceAsk $ask, \App\Offers\Actions\AnswerReprice $answer)
    {
        abort_unless($ask->user_id === $request->user()->id, 404);
        $agree = $request->boolean('agree');
        // Куда после отказа — до ответа: отменённая сделка и снятое подтверждение страницу уже не держат (после конца приёма
        // предложение ему не видно — 404), гаражная — из «Гаража», а не из «Сделок».
        $away = $ask->deal_id ? ($ask->deal?->isGarage() ? '/garage' : '/deals') : '/offers';
        $answer($ask, $agree, $request->user());

        return $agree ? back()->with('toast', 'Новая цена принята')
            : redirect($away)->with('toast', $ask->deal_id ? 'Сделка отменена' : 'Подтверждение снято');
    }

    public function withdraw(Request $request, Bid $bid, WithdrawBid $withdraw)
    {
        abort_unless($bid->user_id === $request->user()->id, 403);
        $withdraw($bid, $request->user());

        return back()->with('toast', 'Подтверждение отозвано');
    }
}
