<?php

namespace App\Http\Admin;

use App\Garage\GaragePayer;
use App\Mail\LetterPrompt;
use App\Offers\Actions\Give;
use App\Offers\Bid;
use App\Offers\Deal;
use App\Offers\DealScheme;
use App\Offers\Giving;
use App\Offers\Offer;
use App\Support\Ago;
use App\Support\Money;
use App\Users\User;
use App\Workflow\Actions\SkipLetter;
use App\Workflow\Position;
use App\Workflow\Stage;
use App\Workflow\Track;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Шторка «Отдать» (`x-offer.give-sheet`, 18.10.2026): одна на «Отдать…» из «Действий» и «···», «В гараж» карточки
 * «Без продажной цены», «Принять» и «Отдать» у подтверждения. Ответ один: тост, строка уходит, карточка — к следующей
 * (`OfferController::gone`). Этап, на который встала сделка, просит письмо вендору — тогда в шторке две кнопки: «и написать
 * письмо» (редактор над той же машиной, окно не уходит, `detail-hold`) или «без письма» (`SkipLetter`); «Отдать» другому —
 * письма нет: вендору о покупке уже писали.
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

    /**
     * «Передать…» гаражную машину другому держателю без отмены сделки (19.11.2026, `HandOverGarage`): кому — менеджер,
     * покупатель с гаражом (с его ценой) или «Себе».
     */
    public function holder(Request $request, Offer $offer, \App\Garage\Actions\HandOverGarage $hand)
    {
        $car = $offer->garageCar()->firstOrFail();
        $request->merge(['buyer_price' => blank($request->input('buyer_price')) ? null : Money::rubles($request->input('buyer_price'))]);
        $data = $request->validate([
            'whom' => ['required', Rule::in(['manager', 'buyer', 'self'])],
            'manager_id' => [Rule::requiredIf($request->input('whom') !== 'self'), 'nullable', 'integer', 'exists:users,id'],
            'buyer_price' => ['nullable', 'integer', 'min:1'],
        ], ['manager_id.required' => 'Кому передать']);
        $to = $data['whom'] === 'self' ? null : User::findOrFail($data['manager_id']);
        $hand($car, $to, $data['buyer_price'] ?? null, $request->user());

        return back()->with('toast', $to ? 'Передана: '.$to->shortName() : 'Взяли под себя');
    }

    private function give(Request $request, Offer $offer, ?Bid $bid, Give $give)
    {
        $request->merge(array_map(fn ($v) => blank($v) ? null : Money::rubles($v), $request->only(['amount', 'commission', 'owner_price', 'buyer_price', 'cost'])));
        // Услуги подбора — со знаком (`Money::signed`): ноль — без подбора, минус — вознаграждение больше разницы.
        $request->merge(['share' => blank($request->input('share')) ? null : Money::signed($request->input('share'))]);
        $garage = $bid ? $bid->isGarage() : $request->input('to') === 'garage';
        $amount = $bid ? $bid->amount : ($request->integer('amount') ?: null);
        $data = $request->validate([
            'to' => ['nullable', Rule::in(['deal', 'garage'])],
            'manager_id' => ['nullable', 'integer', 'exists:users,id'],
            'amount' => ['nullable', 'integer', 'min:1'],
            'commission' => ['nullable', 'integer', 'min:0', 'max:'.max(0, (int) $amount)],
            'scheme' => ['nullable', Rule::enum(DealScheme::class)],
            // По ДКП — что покупатель отдаст страхователю: меньше цены менеджеру, иначе подбору не из чего.
            'owner_price' => ['nullable', 'integer', 'min:1', 'max:'.max(1, (int) $amount)],
            'payer' => ['nullable', Rule::enum(GaragePayer::class)],
            'share' => ['nullable', 'integer', 'min:0'],
            'buyer_price' => ['nullable', 'integer', 'min:1'],
            // Закупочная менеджера в сделке — правит админ при принятии (`Giving::cost`), гаражной её не вписывают.
            'cost' => ['nullable', 'integer', 'min:1'],
            'pickup' => ['nullable', Rule::in(Giving::PICKUP)],
            'where' => ['nullable', Rule::in(array_keys(Giving::WHERE))],
            'arrived_at' => ['nullable', 'date', 'before_or_equal:now'],
            // «Не снимать с продажи» (`x-offer.keep-selling`): сколько ещё часов остальные видят машину.
            'keep_hours' => ['nullable', 'required_if_accepted:keep', 'integer', 'min:1', 'max:168'],
        ], ['arrived_at.before_or_equal' => 'Не позже, чем сейчас', 'share.min' => Giving::SHARE_LEFT,
            'keep_hours.required_if_accepted' => 'Сколько часов', 'keep_hours.integer' => 'Целое число часов',
            'keep_hours.min' => 'Хотя бы час', 'keep_hours.max' => 'Не больше недели']);
        // Дата — только когда ТС уже не у владельца: «Уже у …» в гараж или «Уже забрал» в сделку.
        $already = ($garage && ($data['where'] ?? null) === 'arrived') || (! $garage && ($data['pickup'] ?? null) === 'taken');
        $arrivedAt = $already && ! empty($data['arrived_at']) ? Carbon::parse($data['arrived_at']) : null;
        // «Кому → Менеджеру» без менеджера — не «взяли под себя» молча (19.10.2026): под себя — только явным «Себе».
        if ($garage && ! $bid && in_array($request->input('whom'), ['manager', 'buyer'], true) && empty($data['manager_id'])) {
            throw ValidationException::withMessages(['manager_id' => $request->input('whom') === 'buyer' ? 'Выберите покупателя' : 'Выберите менеджера']);
        }
        if ($request->input('whom') === 'self') {
            $data['manager_id'] = null;
            $data['pickup'] = 'us';
        }
        $scheme = DealScheme::resolve($offer, DealScheme::tryFrom($data['scheme'] ?? ''));
        // Не та схема, что обычно у поставщика (03.11.2026: Land Cruiser 781R приняли «через ПРАЙМ», а договор напрямую) — переспросить.
        if (! $garage && ! $request->boolean('scheme_ok') && ($ask = DealScheme::unusual($offer, $scheme))) {
            throw ValidationException::withMessages(['scheme' => $ask, 'scheme_check' => '1']);
        }
        if (! $garage && $scheme->paysSelection() && ! isset($data['owner_price']) && ! $offer->owner_price && ! $offer->floor_price) {
            throw ValidationException::withMessages(['owner_price' => $scheme === DealScheme::OwnerDkp ? 'Сколько покупатель отдаёт страхователю по ДКП' : 'Сколько покупатель отдаёт страховой']);
        }
        $money = ['scheme' => $scheme, 'ownerPrice' => $data['owner_price'] ?? null, 'commission' => $data['commission'] ?? null,
            'payer' => GaragePayer::tryFrom($data['payer'] ?? ''),
            'share' => $data['share'] ?? null, 'pickup' => $data['pickup'] ?? null, 'where' => $data['where'] ?? 'owner', 'loss' => $request->boolean('loss'),
            'buyerPrice' => $data['buyer_price'] ?? null, 'arrivedAt' => $arrivedAt,
            'cost' => ! $garage && $request->user()->isAdmin() ? ($data['cost'] ?? null) : null,
            // «Отдать… → Поручить параллельно» (07.11.2026): идущая остаётся, этому — параллельная; вывоз не трогаем.
            'parallel' => $bid && $request->boolean('parallel')];
        if ($money['parallel']) {
            $money['pickup'] = null;
        } elseif ($request->boolean('keep')) {
            $money['keepUntil'] = now()->addHours((int) $data['keep_hours']);
        }
        $giving = $bid ? Giving::bid($bid, ...$money)
            : new Giving($offer, isset($data['manager_id']) ? User::find($data['manager_id']) : null, $garage, $amount, ...$money);

        $handOver = $offer->deal()->exists();
        $before = $this->stageId($offer);
        $deal = $give($giving, $request->user());
        if ($deal->isParallel()) {
            return back()->with('toast', 'Поручили параллельно: '.$deal->buyer?->shortName());
        }

        return $this->done($request, $offer, $deal, $handOver ? null : $before, $handOver);
    }

    private function done(Request $request, Offer $offer, Deal $deal, ?int $before, bool $handOver)
    {
        $after = $before ? $this->stageId($offer) : null;
        $letter = $after && $after !== $before ? $offer->fresh()->vendorLetterUrl(Stage::find($after)) : null;
        $who = $deal->buyer?->shortName();
        $toast = match (true) {
            $handOver => 'Отдали '.$who,
            $deal->isGarage() => $who ? 'В гараж: '.$who : 'В гараж, взяли под себя',
            default => 'В сделку: '.$who.', '.Money::rub($deal->amount),
        };
        if ($request->boolean('keep') && ($fresh = $offer->fresh())->showsDealAsOpen()) {
            $toast .= ', в продаже до '.Ago::at($fresh->deal_show_until, true, words: false);
        }
        // Писать ли поставщику, выбирают кнопкой самой шторки (18.11.2026, владелец: «должен явно указывать — отправить письмо
        // или не отправлять, только тогда оно может в следующую вкладку переехать»; `x-offer.give-foot`).
        // «Без письма» — шаг закрыт как «сообщили сами», дальше обычный ответ: строка ушла, окно — к следующему.
        if ($letter && $request->input('letter') === 'skip') {
            try {
                app(SkipLetter::class)($offer->fresh(), $request->user());
            } catch (ValidationException) {
                // Шаг письма уже не ждёт — закрывать нечего.
            }
            $letter = null;
        }
        // С письмом — окно машины стоит на месте (`detail-hold`), пока открыт редактор: прежний ответ «строка ушла, окно — к
        // следующей» закрывал окно машины, а с ним и письмо над ним (в журнале прода `letter-closed` по 4–7 раз в день).
        // Письмо — из очереди человека на сервере (`LetterPrompt`), не из сессии: метку сессии съедал соседний запрос.
        // Без выбора (форма со старой страницы) — прежний вопрос «писать / не отправлять».
        if ($letter) {
            $request->input('letter') === 'write'
                ? LetterPrompt::push($request->user(), $letter.'&then=close')
                : LetterPrompt::ask($request->user(), $offer, $letter.'&then=close');

            return back()->with('toast', $toast)->with('detail-hold', true);
        }

        // Шторки «Отдано: …» со ссылкой больше нет (16.11.2026, владелец: «бесполезная, её не надо открывать»; она же ждала окно
        // письма и вставала вместо редактора) — ссылка тому, кому отдали, строкой «Ссылка» в окне машины (`x-deal.person-link`).
        return back()->with('toast', $toast)->with(OfferController::gone($request, $offer));
    }

    /** Этап продажи предложения — только номер: сравнить до и после. */
    private function stageId(Offer $offer): ?int
    {
        return Position::where('offer_id', $offer->id)->where('track', Track::Sale)->value('stage_id');
    }
}
