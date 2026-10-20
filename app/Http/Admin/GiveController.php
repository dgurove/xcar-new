<?php

namespace App\Http\Admin;

use App\Garage\GaragePayer;
use App\Mail\LetterPrompt;
use App\Offers\Actions\Give;
use App\Offers\Bid;
use App\Offers\CommissionMode;
use App\Offers\Deal;
use App\Offers\DealScheme;
use App\Offers\Giving;
use App\Offers\Offer;
use App\Support\Money;
use App\Users\User;
use App\Workflow\Position;
use App\Workflow\Stage;
use App\Workflow\Track;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Шторка «Отдать» (`x-offer.give-sheet`, 18.10.2026): одна на «Отдать…» из «Действий» и «···», «В гараж» карточки
 * «Без продажной цены», «Принять» и «Отдать» у подтверждения. Ответ один: тост, строка уходит, карточка — к следующей
 * (`OfferController::gone`), а этап, на который встала сделка, просит письмо вендору — окно писем с черновиком
 * (`LetterPrompt`; «Отдать» другому — нет: вендору о покупке уже писали).
 */
class GiveController
{
    public function store(Request $request, Offer $offer, Give $give)
    {
        return $this->give($request, $offer, null, $give);
    }

    public function accept(Request $request, Bid $bid, Give $give)
    {
        return $this->give($request, $bid->offer, $bid, $give);
    }

    private function give(Request $request, Offer $offer, ?Bid $bid, Give $give)
    {
        $request->merge(array_map(fn ($v) => blank($v) ? null : Money::rubles($v), $request->only(['amount', 'commission', 'owner_price', 'share', 'buyer_price'])));
        $garage = $bid ? $bid->isGarage() : $request->input('to') === 'garage';
        $amount = $bid ? $bid->amount : ($request->integer('amount') ?: null);
        $data = $request->validate([
            'to' => ['nullable', Rule::in(['deal', 'garage'])],
            'manager_id' => ['nullable', 'integer', 'exists:users,id'],
            'amount' => ['nullable', 'integer', 'min:1'],
            'commission' => ['nullable', 'integer', 'min:0', 'max:'.max(0, (int) $amount)],
            'mode' => ['nullable', Rule::enum(CommissionMode::class)],
            'scheme' => ['nullable', Rule::enum(DealScheme::class)],
            // По ДКП — что покупатель отдаст страхователю: меньше цены менеджеру, иначе подбору не из чего.
            'owner_price' => ['nullable', 'integer', 'min:1', 'max:'.max(1, (int) $amount)],
            'payer' => ['nullable', Rule::enum(GaragePayer::class)],
            'share' => ['nullable', 'integer', 'min:1'],
            'buyer_price' => ['nullable', 'integer', 'min:1'],
            'pickup' => ['nullable', Rule::in(Giving::PICKUP)],
            'where' => ['nullable', Rule::in(array_keys(Giving::WHERE))],
        ]);
        // «Кому → Менеджеру» без менеджера — не «взяли под себя» молча (19.10.2026): под себя — только явным «Себе».
        if ($garage && ! $bid && in_array($request->input('whom'), ['manager', 'buyer'], true) && empty($data['manager_id'])) {
            throw ValidationException::withMessages(['manager_id' => $request->input('whom') === 'buyer' ? 'Выберите покупателя' : 'Выберите менеджера']);
        }
        if ($request->input('whom') === 'self') {
            $data['manager_id'] = null;
            $data['pickup'] = 'us';
        }
        $scheme = DealScheme::resolve($offer, DealScheme::tryFrom($data['scheme'] ?? ''));
        if (! $garage && $scheme->paysSelection() && ! isset($data['owner_price']) && ! $offer->owner_price && ! $offer->floor_price) {
            throw ValidationException::withMessages(['owner_price' => $scheme === DealScheme::OwnerDkp ? 'Сколько покупатель отдаёт страхователю по ДКП' : 'Сколько покупатель отдаёт страховой']);
        }
        $money = ['scheme' => $scheme, 'ownerPrice' => $data['owner_price'] ?? null, 'commission' => $data['commission'] ?? null,
            'mode' => CommissionMode::tryFrom($data['mode'] ?? '') ?? CommissionMode::Payout, 'payer' => GaragePayer::tryFrom($data['payer'] ?? ''),
            'share' => $data['share'] ?? null, 'pickup' => $data['pickup'] ?? null, 'where' => $data['where'] ?? 'owner', 'loss' => $request->boolean('loss'),
            'buyerPrice' => $data['buyer_price'] ?? null];
        $giving = $bid ? Giving::bid($bid, ...$money)
            : new Giving($offer, isset($data['manager_id']) ? User::find($data['manager_id']) : null, $garage, $amount, ...$money);

        $handOver = $offer->deal()->exists();
        $before = $this->stageId($offer);
        $deal = $give($giving, $request->user());

        return $this->done($request, $offer, $deal, $handOver ? null : $before, $handOver);
    }

    private function done(Request $request, Offer $offer, Deal $deal, ?int $before, bool $handOver)
    {
        $after = $before ? $this->stageId($offer) : null;
        $letter = $after && $after !== $before ? $offer->fresh()->vendorLetterUrl(Stage::find($after)) : null;
        // Окно письма — из очереди человека на сервере (`LetterPrompt`), не из сессии: метку сессии съедал соседний запрос.
        if ($letter) {
            LetterPrompt::push($request->user(), $letter.'&then=close');
        }
        $who = $deal->buyer?->shortName();
        $toast = match (true) {
            $handOver => 'Отдали '.$who,
            $deal->isGarage() => $who ? 'В гараж: '.$who : 'В гараж, взяли под себя',
            default => 'В сделку: '.$who.', '.Money::rub($deal->amount),
        };

        return back()->with('toast', $toast)->with(OfferController::gone($request, $offer));
    }

    /** Этап продажи предложения — только номер: сравнить до и после. */
    private function stageId(Offer $offer): ?int
    {
        return Position::where('offer_id', $offer->id)->where('track', Track::Sale)->value('stage_id');
    }
}
