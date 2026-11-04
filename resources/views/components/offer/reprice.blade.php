{{-- Переоценка на решение (28.10.2026, владелец: «по такому-то письму такая-то переоценка, с возможностью принять или
     отменить»; `Offers\Reprice`). Плашка наверху окошка и редактора: было → стало, откуда, «Открыть письмо», «Отклонить» и
     «Применить…». Шторка — новая цена (правится), у открытого цена продажи (заявленная пустая — идёт за закупочной), и решение по каждому, кто сейчас
     подтвердил, и по сделке: «Оставить | Обновить | Спросить» в любую сторону (03.11.2026, владелец: менеджера спрашивает только
     админ; «Спросить» — вопрос `RepriceAsk` цифрами, ни слова письма; кто разницы не видит — без «Спросить»), заранее не
     отмечено ничего, «Применить» — когда решено по всем (`reprice_controller`). Вписали закупочную в редакторе при подтвердивших — шторка открывается у автора сама, один раз. --}}
@props(['offer', 'reprice'])
@php
    use App\Support\Money;
    ['bids' => $bids, 'deal' => $deal] = \App\Offers\Reprice::affected($offer);
    $id = 'reprice-'.$reprice->id;
    $diff = $reprice->to_price - (int) $reprice->from_price;
    // Админ вписал закупочную в редакторе — шторка открывается один раз, по метке `DetectReprice::open`.
    $mine = session('reprice-open') == $reprice->id;
    $dealLocked = $deal && ! $deal->moneyEditable();
    $open = $offer->state === \App\Offers\OfferState::Open;
@endphp
<div {{ $attributes->class('proposal reprice') }} data-controller="sheet">
    <div class="proposal-body">
        <span class="proposal-mark" aria-hidden="true"><x-ui.icon :name="$reprice->source === 'letter' ? 'mail' : 'edit'" class="size-4"/></span>
        <div class="min-w-0 flex-1">
            <p class="text-ink">Переоценка {{ $reprice->sourceLabel() }}</p>
            <p class="nums text-sm text-ink-muted"><s>{{ Money::nums((int) $reprice->from_price) }}</s> → <span class="font-semibold text-ink">{{ Money::rub($reprice->to_price) }}</span> ({{ $diff > 0 ? '+' : '−' }}{{ Money::nums(abs($diff)) }})</p>
            {{-- У Альфы в письме — оценочная, закупочная — от неё (04.11.2026, владелец: «пишется, что новая закупочная, — неправда»). --}}
            @if ($reprice->value)<p class="nums text-sm text-ink-muted">оценочная {{ Money::nums($reprice->value) }} + {{ Money::nums($reprice->to_price - $reprice->value) }}</p>@endif
        </div>
    </div>
    <div class="proposal-acts">
        @if ($reprice->message_id)<x-mail.window-button :url="'/offers/'.$offer->number.'/letters?at='.$reprice->message_id" label="Письмо" class="btn-s"/>@endif
        <form method="post" action="/offers/{{ $offer->number }}/reprices/{{ $reprice->id }}" data-turbo-confirm="Отклонить переоценку? Закупочная останется {{ Money::rub((int) $reprice->from_price) }}" data-turbo-confirm-label="Отклонить">
            @csrf @method('delete')
            <button class="btn btn-s btn-quiet text-danger">Отклонить</button>
        </form>
        <button type="button" class="btn btn-s btn-accent" data-action="sheet#open" aria-controls="{{ $id }}">Применить…</button>
    </div>
    <x-ui.sheet :id="$id" title="Переоценка" tall :open="$mine || old('_reprice') == $reprice->id">
        <form method="post" action="/offers/{{ $offer->number }}/reprices/{{ $reprice->id }}" class="flex flex-col gap-4" data-controller="reprice" @if ($offer->state === \App\Offers\OfferState::Open) data-price-guard="whole" data-price-guard-offer="{{ $offer->number }}" data-price-guard-floor="price" @endif
              data-reprice-from-value="{{ (int) $reprice->from_price }}">
            @csrf @if ($errors->has('price_check'))<input type="hidden" name="price_ok" value="1">@endif
            <input type="hidden" name="_reprice" value="{{ $reprice->id }}">
            @error('reprice')<x-ui.flash tone="danger">{{ $message }}</x-ui.flash>@enderror
            {{-- Оценочная из письма и выбор правила переоценки: +50 000 или +5 % (`Pricing::rebids`), меньшее — сразу в поле. --}}
            @if ($reprice->value)
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <span class="text-ink-muted">Оценочная по письму <span class="nums text-ink">{{ Money::rub($reprice->value) }}</span></span>
                    <span class="flex gap-2">
                        @foreach (\App\Offers\Pricing::rebids($reprice->value) as $label => $rebid)
                            <button type="button" class="chip nums" data-action="reprice#pick" data-reprice-price-param="{{ $rebid }}">{{ $label }}</button>
                        @endforeach
                    </span>
                </div>
            @endif
            <div class="fields">
                <x-ui.field name="price" label="Закупочная, ₽" :value="Money::nums((int) old('price', $reprice->to_price))" :id="$id.'-price'" required
                    data-reprice-target="price" data-action="input->reprice#update"/>
                @if ($open)
                    <x-ui.field name="asking_price" label="Цена продажи, ₽" :value="$offer->asking_price ? Money::nums($offer->asking_price) : null" :id="$id.'-asking'"/>
                @endif
            </div>
            @if ($bids->isNotEmpty() || $deal)
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <span class="field-label">Закупочная у тех, кто уже подтвердил</span>
                    <span class="flex items-center gap-2 whitespace-nowrap">
                        <span class="text-sm text-ink-muted">Всем</span>
                        <button type="button" class="chip" data-action="reprice#all" data-reprice-choice-param="keep">оставить</button>
                        <button type="button" class="chip" data-action="reprice#all" data-reprice-choice-param="update">обновить</button>
                        <button type="button" class="chip" data-action="reprice#all" data-reprice-choice-param="ask">спросить</button>
                    </span>
                </div>
                <div class="list">
                    @foreach ($bids as $bid)
                        @php
                            $cost = (int) $bid->frozenCost();
                            $asks = \App\Offers\RepriceAsk::shownFor($bid->user, $bid, $offer, $cost, $reprice->to_price) !== null;
                        @endphp
                        <div class="row flex-col !items-stretch gap-2" data-reprice-target="row" data-amount="{{ (int) $bid->amount }}">
                            <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                <x-ui.person :user="$bid->user" full/>
                                <span class="nums text-sm text-ink-muted">{{ $bid->isGarage() ? 'в гараж' : Money::rub($bid->amount) }}, закупочная {{ Money::nums($cost) }} → <span data-reprice-target="to">{{ Money::nums($reprice->to_price) }}</span></span>
                            </div>
                            <div class="segment">
                                <label><input type="radio" name="bids[{{ $bid->id }}]" value="keep" @checked(old('bids.'.$bid->id) === 'keep') data-action="reprice#update">Оставить</label>
                                <label><input type="radio" name="bids[{{ $bid->id }}]" value="update" @checked(old('bids.'.$bid->id) === 'update') data-action="reprice#update">Обновить</label>
                                @if ($asks)<label><input type="radio" name="bids[{{ $bid->id }}]" value="ask" @checked(old('bids.'.$bid->id) === 'ask') data-action="reprice#update">Спросить</label>@endif
                            </div>
                            <p class="text-sm text-danger" data-reprice-target="loss" hidden></p>
                        </div>
                    @endforeach
                    @if ($deal)
                        <div class="row flex-col !items-stretch gap-2" data-reprice-target="row" data-amount="{{ (int) $deal->amount }}">
                            <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                <span class="font-medium">Сделка</span>@if ($deal->buyer)<x-ui.person :user="$deal->buyer" full/>@endif
                                <span class="nums text-sm text-ink-muted">закупочная {{ Money::nums((int) $deal->cost) }} → <span data-reprice-target="to">{{ Money::nums($reprice->to_price) }}</span></span>
                            </div>
                            {{-- По счёту уже платили — только «Оставить»: вместо других — словом, почему. --}}
                            <div class="segment">
                                <label><input type="radio" name="deal" value="keep" @checked(old('deal') === 'keep' || $dealLocked) data-action="reprice#update">Оставить</label>
                                @if ($dealLocked)
                                    <label class="opacity-40"><input type="radio" disabled>Уже платили</label>
                                @else
                                    <label><input type="radio" name="deal" value="update" @checked(old('deal') === 'update') data-action="reprice#update">Обновить</label>
                                    @if (\App\Offers\RepriceAsk::shownFor($deal->buyer, $deal, $offer, (int) $deal->cost, $reprice->to_price) !== null)
                                        <label><input type="radio" name="deal" value="ask" @checked(old('deal') === 'ask') data-action="reprice#update">Спросить</label>
                                    @endif
                                @endif
                            </div>
                            <p class="text-sm text-danger" data-reprice-target="loss" hidden></p>
                        </div>
                    @endif
                </div>
            @endif
            <x-ui.sheet-foot type="submit" data-reprice-target="submit">Применить переоценку</x-ui.sheet-foot>
        </form>
    </x-ui.sheet>
</div>
