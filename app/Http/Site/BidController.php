<?php

namespace App\Http\Site;

use App\Offers\Actions\AnswerCounter;
use App\Offers\Actions\AnswerReprice;
use App\Offers\Actions\PlaceBid;
use App\Offers\Actions\PlaceBidFor;
use App\Offers\Actions\RemindCounter;
use App\Offers\Actions\ReviseBid;
use App\Offers\Actions\WithdrawBid;
use App\Offers\Bid;
use App\Offers\BidKind;
use App\Offers\Offer;
use App\Offers\RepriceAsk;
use App\Support\Money;
use App\Users\Role;
use App\Users\User;
use App\Workflow\Actions\RemindAsks;
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

        // Резерв при идущей сделке: передать сразу могло не выйти — тогда его ответ принят, передадут руками (`AnswerCounter`).
        $deal = $bid->offer->deal()->first();
        $mine = $deal && $deal->buyer_id === $request->user()->id;

        return $agree ? redirect($mine ? $deal->href() : '/offers/'.$bid->offer->number)->with('toast', $mine ? 'Сделка открыта' : 'Согласие принято')
            : back()->with('toast', 'Вы отказались, подтверждение снято');
    }

    /** «Напомнить через…» в ответе на контрпредложение (19.11.2026): часы снова пришлют его (`RemindCounter`). Пусто — снять. */
    public function remindCounter(Request $request, Bid $bid)
    {
        abort_unless($bid->user_id === $request->user()->id && $bid->hasPendingCounter(), 404);
        $at = RemindAsks::at((string) $request->input('when'));
        RemindCounter::set($bid, $at, $request->user());

        return back()->with('toast', $at ? 'Напомним '.RemindAsks::when($at) : 'Напоминание снято');
    }

    /** Выросла закупочная: «Согласен» — новая цена, «Отказываюсь» — сделка отменена или подтверждение снято. */
    public function answerReprice(Request $request, RepriceAsk $ask, AnswerReprice $answer)
    {
        abort_unless($ask->user_id === $request->user()->id, 404);
        // Цена (10.11.2026, владелец: «его просит ввести новую цену подтверждения»), согласие или нет на наше контрпредложение, отказ.
        $do = $request->input('do', $request->has('agree') && ! $request->boolean('agree') ? 'decline' : 'price');
        // Куда после отказа — до ответа: отменённая сделка и снятое подтверждение страницу уже не держат (после конца приёма
        // предложение ему не видно — 404), гаражная — из «Гаража», а не из «Сделок».
        $away = $ask->deal_id ? ($ask->deal?->isGarage() ? '/garage' : '/deals') : '/offers';
        $by = $request->user();
        if ($do === 'decline') {
            $answer->decline($ask, $by);

            return redirect($away)->with('toast', $ask->deal_id ? 'Сделка отменена' : 'Подтверждение снято');
        }
        if ($do === 'agree_cost') {
            $answer->agreeCost($ask, $by);

            return back()->with('toast', 'Согласились на новую закупочную');
        }
        if ($do === 'agree') {
            $answer->agree($ask, $by);

            return back()->with('toast', 'Новая цена принята');
        }
        if ($do === 'refuse') {
            $answer->refuseCounter($ask, $by);

            return back()->with('toast', 'Ваша цена в силе — ждём решения');
        }
        $answer->offer($ask, Money::rubles($request->validate(['price' => 'required'])['price']), $by, $request->boolean('low_ok'));

        return back()->with('toast', $ask->deal_id ? 'Цена отправлена — ждём решения' : 'Новая цена принята');
    }

    /**
     * «Изменить» у своего подтверждения (22.11.2026, владелец: «пока ждёт решения» — и после конца приёма): «Для покупателя» с
     * ценой или «В гараж», `ReviseBid` — цена зонами, ниже порога и «вдвое выше» переспрашивает (`low_ok`).
     */
    public function revise(Request $request, Bid $bid, ReviseBid $revise)
    {
        abort_unless($bid->user_id === $request->user()->id, 403);
        $kind = BidKind::tryFrom((string) $request->input('kind')) ?? BidKind::Buyer;
        $data = $request->validate(['amount' => [$kind === BidKind::Buyer ? 'required' : 'nullable', 'string', 'max:20']], ['amount.required' => 'Впишите цену']);
        $bid = $revise($bid, $kind, Money::rubles($data['amount'] ?? null) ?: null, $request->user(), $request->boolean('low_ok'));

        return back()->with('toast', $bid->kind === BidKind::Garage ? 'Подтверждение: в гараж' : 'Подтверждение: '.Money::rub((int) $bid->amount));
    }

    public function withdraw(Request $request, Bid $bid, WithdrawBid $withdraw)
    {
        abort_unless($bid->user_id === $request->user()->id, 403);
        $withdraw($bid, $request->user());

        return back()->with('toast', 'Подтверждение отозвано');
    }
}
