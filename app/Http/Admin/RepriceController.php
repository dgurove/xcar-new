<?php

namespace App\Http\Admin;

use App\Offers\Actions\AnswerReprice;
use App\Offers\Actions\ApplyReprice;
use App\Offers\Actions\DismissReprice;
use App\Offers\Actions\PublishDeal;
use App\Offers\Actions\UpdateOffer;
use App\Offers\Offer;
use App\Offers\OfferEventType;
use App\Offers\OfferState;
use App\Offers\PriceGuard;
use App\Offers\Reprice;
use App\Offers\RepriceAsk;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Плашка и шторка «Переоценка» (`x-offer.reprice`, 28.10.2026): применить с решением по каждому подтвердившему или отклонить. */
class RepriceController
{
    public function apply(Request $request, Offer $offer, Reprice $reprice, ApplyReprice $apply)
    {
        abort_unless($reprice->offer_id === $offer->id, 404);
        $request->merge(array_map(fn ($v) => blank($v) ? null : Money::rubles($v), $request->only(['price', 'publish_price', 'asking_price'])));
        $data = $request->validate([
            'price' => ['required', 'integer', 'min:1'],
            'publish_price' => ['nullable', 'integer', 'min:1'],
            'asking_price' => ['nullable', 'integer', 'min:1'],
            'bids' => ['array'],
            'bids.*' => [Rule::in([ApplyReprice::KEEP, ApplyReprice::UPDATE, ApplyReprice::ASK])],
            'deal' => ['nullable', Rule::in([ApplyReprice::KEEP, ApplyReprice::UPDATE, ApplyReprice::ASK])],
            'show_all' => ['boolean'],
            'show_until' => ['nullable', 'date', 'after:now'],
            'audience_rules' => ['nullable', 'json'],
        ], ['price.required' => 'Новая закупочная', 'show_until.after' => 'Срок публикации уже прошёл']);
        // Цена продажи — в любом состоянии (04.11.2026, владелец: «старая цена продажи ниже новой закупочной»): в сделке по
        // ней машину увидят все, если показывать, пока менеджер думает.
        $prices = array_filter(array_intersect_key($data, array_flip(['asking_price'])));
        // Открытое: новая закупочная против цены продажи — «Точно?» (`PriceGuard`), её правят тут же в шторке.
        if ($offer->state === OfferState::Open) {
            PriceGuard::assertPrices($prices['asking_price'] ?? $offer->asking_price, $data['price'], $request->boolean('price_ok'));
        }
        // Пока менеджер сделки думает — опубликовать для других (`PublishDeal`): тот же срок и «Кому», что в шторке публикации.
        $publish = null;
        if (($data['deal'] ?? null) === ApplyReprice::ASK && $request->boolean('show_all')) {
            $until = filled($data['show_until'] ?? null) ? Carbon::parse($data['show_until']) : \App\Offers\Binding::clampClose($offer, \App\Offers\Slots::closeFor(now(), 7));
            if ($error = \App\Offers\Binding::closeError($offer, $until)) {
                throw ValidationException::withMessages(['show_until' => $error]);
            }
            $publish = ['rules' => json_decode((string) ($data['audience_rules'] ?? ''), true) ?: [], 'until' => $until];
            // До переоценки, а не после: публикация идёт вслед за ней, и «никому» не должно оставить переоценку применённой наполовину.
            if (\App\Offers\AudienceRules::nobody($publish['rules'] ?: \App\Offers\AudienceRules::everyone())) {
                throw ValidationException::withMessages(['audience_rules' => 'Выберите, кому показывать']);
            }
        }
        $apply($reprice, $request->user(), $data['price'], $data['bids'] ?? [], $data['deal'] ?? null, $prices, $publish);

        return back(fallback: "/offers/{$offer->number}")->with('toast', 'Переоценка применена');
    }

    /**
     * Новая цена после переоценки (10.11.2026): за менеджера (он сказал устно) — цена, «согласен» / «отказался» на наше
     * контрпредложение, отказ; наше решение по названной им цене — принять, контрпредложение, отказать.
     */
    public function answer(Request $request, Offer $offer, RepriceAsk $ask, AnswerReprice $answer)
    {
        abort_unless($ask->offer_id === $offer->id, 404);
        $by = $request->user();
        $do = $request->input('do', $request->has('agree') && ! $request->boolean('agree') ? 'decline' : 'price');
        $rub = fn (string $key) => Money::rubles($request->validate([$key => 'required'])[$key]);
        $offered = $ask->isOffered();
        if ($do === 'accept') {
            $answer->accept($ask, $by);
            $toast = 'Цена принята';
        } elseif ($do === 'counter') {
            $answer->counter($ask, $rub('counter_price'), $by);
            $toast = 'Контрпредложение отправлено';
        } elseif ($do === 'agree') {
            $answer->agree($ask, $by);
            $toast = 'За менеджера: согласен';
        } elseif ($do === 'refuse') {
            $answer->refuseCounter($ask, $by);
            $toast = 'За менеджера: не согласен с контрпредложением';
        } elseif ($do === 'decline') {
            $answer->decline($ask, $by);
            $toast = $offered ? 'Отказали менеджеру' : 'За менеджера: отказался';
        } else {
            $answer->offer($ask, $price = $rub('reprice_price'), $by);
            $toast = 'За менеджера: цена '.Money::rub($price);
        }

        return back(fallback: "/offers/{$offer->number}")->with('toast', $toast);
    }

    /**
     * «Опубликовать для других менеджеров» из «⋯» идущей сделки и гаражной (12.11.2026, владелец: машина в сделке у менеджера,
     * а начальник хочет показать её остальным; потом — «всем, кроме»): кому — правила «кто → когда», как волны предложения,
     * и срок приёма, не позже обязывающего, сверху — заявленная и цена продажи предложения. Идёт — та же шторка правит всё это
     * (`PublishDeal`).
     */
    public function publish(Request $request, Offer $offer, PublishDeal $publish, UpdateOffer $update)
    {
        $request->merge(array_map(fn ($v) => blank($v) ? null : Money::rubles($v), $request->only(['publish_price', 'asking_price'])));
        $data = $request->validate(['until' => ['required', 'date', 'after:now'], 'audience_rules' => ['nullable', 'json'],
            'publish_price' => ['nullable', 'integer', 'min:1'], 'asking_price' => ['required', 'integer', 'min:1']],
            ['until.required' => 'До когда', 'until.after' => 'Срок уже прошёл', 'asking_price.required' => 'Укажите цену продажи']);
        $until = Carbon::parse($data['until']);
        $offer->loadMissing('deal');
        if (! PublishDeal::allows($offer)) {
            throw ValidationException::withMessages(['until' => 'Опубликовать так можно только машину в идущей сделке']);
        }
        if ($error = \App\Offers\Binding::closeError($offer, $until)) {
            throw ValidationException::withMessages(['until' => $error]);
        }
        $running = $offer->showsDealAsOpen();
        // Цены — предложения, не сделки (12.11.2026, начальник): их видят те, кому публикуем; сделку держат `deals.amount` и
        // `deals.cost`. Закупочная не правится — значит, и переоценки нет. Под вопросом против закупочной — «Точно?»: при
        // публикации и когда цену продажи поменяли, а не при каждой правке «Кому» (шторка — `data-price-guard-changed`).
        if (! $running || $data['asking_price'] !== $offer->asking_price) {
            PriceGuard::assertPrices($data['asking_price'], $offer->floor_price, $request->boolean('price_ok'));
        }
        // Заявленная стоит в поле числом (серая подсказка читалась как выключенное поле): не трогали расчёт от закупочной — пусто,
        // заявленная и дальше идёт за закупочной.
        $declared = $data['publish_price'] ?? null;
        if ($offer->publish_price === null && $declared === Offer::declaredFrom($offer->floor_price)) {
            $declared = null;
        }
        $prices = array_filter(['publish_price' => $declared, 'asking_price' => $data['asking_price']],
            fn ($v, $k) => $v !== $offer->{$k}, ARRAY_FILTER_USE_BOTH);
        // Одной транзакцией: «Выберите, кому показывать» откатывает и цены.
        DB::transaction(function () use ($offer, $prices, $update, $publish, $data, $until, $request) {
            if ($prices) {
                $update($offer, $prices, $request->user(), ['why' => 'Публикация для других менеджеров']);
            }
            $publish($offer, json_decode((string) ($data['audience_rules'] ?? ''), true) ?: [], $until, $request->user());
        });

        return back(fallback: "/offers/{$offer->number}")->with('toast', $running ? 'Публикация изменена' : 'Опубликовано для других менеджеров до '.\App\Support\Ago::at($until, true, words: false));
    }

    public function unpublish(Request $request, Offer $offer, PublishDeal $publish)
    {
        $publish->stop($offer, $request->user());

        return back(fallback: "/offers/{$offer->number}")->with('toast', 'Публикация для других менеджеров снята');
    }

    /** «Ответили не письмом» (шаг «Переоценка», 04.11.2026): поставщику ответили по телефону или из своей почты. */
    public function answered(Request $request, Offer $offer, Reprice $reprice)
    {
        abort_unless($reprice->offer_id === $offer->id, 404);
        if (! $reprice->answered_at) {
            $reprice->update(['answered_at' => now(), 'answered_by' => $request->user()->id]);
            $offer->log(OfferEventType::Note, $request->user(), ['text' => 'Поставщику ответили про переоценку не письмом']);
        }

        return back(fallback: "/offers/{$offer->number}")->with('toast', 'Отмечено: поставщику ответили');
    }

    public function dismiss(Request $request, Offer $offer, Reprice $reprice, DismissReprice $dismiss)
    {
        abort_unless($reprice->offer_id === $offer->id, 404);
        $dismiss($reprice->setRelation('offer', $offer), $request->user());

        return back(fallback: "/offers/{$offer->number}")->with('toast', 'Переоценка отклонена');
    }
}
