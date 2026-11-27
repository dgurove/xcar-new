{{-- Шторки экрана машины. Каждую открывает событие «имя:open» — с главной кнопки или из «⋯».
     07.10.2026: поля строками (.fields), кнопка .sheet-foot, вознаграждение в «Выставить счёт» сразу под сводкой. --}}
@php
    use App\Support\Money;
    $wrap = fn (string $name) => 'data-controller="sheet" data-action="'.$name.':open@window->sheet#open" class="contents"';
    // Сумма в поле — разрядами, копейки двумя цифрами: «15 000,50», а не «15000,5».
    $sum = fn ($v) => Money::nums($v, fmod((float) $v, 1) ? 2 : 0);
    // Плательщик по продаже — любой контрагент или новый, как в счёте сделки.
    $parties = $staff && $car->state === \App\Garage\CarState::Sold && ! $invoice
        ? \App\Billing\Party::where('is_self', false)->orderBy('name')->pluck('name', 'id')->prepend('Новый плательщик', 'new') : collect();
    // Продажа Совкомбанка (12.11.2026): под ценой — расчёт строками (`garage.cars.sell-split`), цена под вопросом —
    // лист до отправки (`price-guard.js` режим `sold`), без скрипта — ошибка `price_check` и согласие на повтор.
    $sellDeal = $car->settledByDeal() ? $car->deal : null;
    $sellForm = fn () => ($sellDeal ? 'data-controller="sell-split" data-sell-split-cost-value="'.(int) $sellDeal->cost.'" data-sell-split-spent-value="'.$sellDeal->managerGarageCosts()
        .'" data-sell-split-share-value="'.$sellDeal->ourGarageShare().'" data-sell-split-paid-value="'.$sellDeal->credited().'" ' : '')
        .'data-price-guard="sold" data-price-guard-url="/garage/cars/'.$n.'/price-check"';
    $sellAgain = $errors->has('price_check');
@endphp

@if ($canAdd)
    <div {!! $wrap('cost-new') !!}>
        {{-- Открыта после ошибки — только своей (`_sheet`): у «Цены и вознаграждения» поле той же суммы `amount`, и её ошибка
             открывала расход поверх (27.11.2026, 467056). Без `_sheet` (форма без шторки) — как раньше. --}}
        <x-ui.sheet id="cost-new" title="Записать расход" :open="($errors->has('title') || $errors->has('amount')) && in_array(old('_sheet'), [null, 'cost-new'], true) && ! $unpaid">
            <form method="post" action="/garage/cars/{{ $n }}/costs" class="flex flex-col gap-3">
                @csrf
                @include('garage.cars.cost-fields', ['cost' => null, 'sheet' => 'cost-new'])
                <x-ui.sheet-foot type="submit" variant="primary">Записать</x-ui.sheet-foot>
            </form>
        </x-ui.sheet>
    </div>
@endif

@if (! $staff && $car->state === \App\Garage\CarState::Selling)
    {{-- «Продаю»: только цена — её не отклоняем, вознаграждение и счёт дальше наши. --}}
    <div {!! $wrap('selling') !!}>
        <x-ui.sheet id="selling" title="Продаю">
            <form method="post" action="/garage/cars/{{ $n }}/sold" class="flex flex-col gap-4" {!! $sellForm() !!}>
                @csrf @if ($sellAgain)<input type="hidden" name="price_ok" value="1">@endif
                <x-ui.field name="sold_price" label="За сколько, ₽" :value="old('sold_price')" required autofocus data-sell-split-target="price" data-action="input->sell-split#update"/>
                @if ($sellDeal)
                    <div class="list">@include('garage.cars.sell-split', ['deal' => $sellDeal, 'sale' => Money::rubles(old('sold_price')), 'live' => true])</div>
                @endif
                {{-- «Вы уверены?» (19.11.2026, `Offers\Warnings`): «Не продана» — только у сотрудника. После «Точно?» цены — без второго окна. --}}
                <x-ui.sheet-foot type="submit" variant="primary" :data-turbo-confirm="$sellAgain ? null : \App\Offers\Warnings::TITLE" :data-turbo-confirm-text="$sellAgain ? null : \App\Offers\Warnings::SOLD"
                    :data-turbo-confirm-label="$sellAgain ? null : 'Да, продал'" :data-turbo-confirm-cancel="$sellAgain ? null : \App\Offers\Warnings::CANCEL">{{ $sellAgain ? 'Всё верно, продаю' : 'Продаю' }}</x-ui.sheet-foot>
            </form>
        </x-ui.sheet>
    </div>
@endif

{{-- Сотрудник вносит итог и с подготовки (`MarkGarageSold`: любой рабочий этап; 12.11.2026 — «Себе» Совкомбанка продали, пока готовили). --}}
@if ($staff && ($car->state === \App\Garage\CarState::Selling || $car->state->isWorking() && ! $car->forBuyer()))
    <div {!! $wrap('sold') !!}>
        <x-ui.sheet id="sold" title="Продана" :open="request()->boolean('sold')">
            <form method="post" action="/garage/cars/{{ $n }}/sold" class="flex flex-col gap-3" {!! $sellForm() !!}>
                @csrf @if ($sellAgain)<input type="hidden" name="price_ok" value="1">@endif
                <div class="fields">
                    <x-ui.field name="sold_price" label="За сколько, ₽" :value="old('sold_price')" autofocus data-sell-split-target="price" data-action="input->sell-split#update"/>
                    <x-ui.field name="sold_at" label="Когда" type="date" :value="now()->toDateString()" max="{{ now()->toDateString() }}"/>
                    {{-- Покупатель Совкомбанка — в сделке (договор и счёт), второго имени у машины не нужно. --}}
                    @unless ($sellDeal)
                        <x-ui.field name="buyer_name" label="Покупатель" :value="old('buyer_name')"/>
                        <x-ui.field name="buyer_phone" label="Телефон" :value="old('buyer_phone')"/>
                    @endunless
                </div>
                @if ($sellDeal)
                    <div class="list">@include('garage.cars.sell-split', ['deal' => $sellDeal, 'sale' => Money::rubles(old('sold_price')), 'live' => true, 'dense' => true])</div>
                @endif
                <x-ui.sheet-foot type="submit" variant="primary">{{ $sellAgain ? 'Всё верно, продана' : 'Продана' }}</x-ui.sheet-foot>
            </form>
        </x-ui.sheet>
    </div>
@endif

@if ($staff && $car->state === \App\Garage\CarState::Sold && ! $invoice)
    {{-- Вознаграждение вводится здесь же: суммы ниже пересчитываются на месте. Счёт — цена минус расходы менеджера минус
         вознаграждение, кто бы ни платил (18.11.2026: выплат менеджеру нет); ноль и меньше — расчёт закрывается без документа. --}}
    <div {!! $wrap('settle') !!}>
        <x-ui.sheet id="settle" :title="$car->manager ? 'Выставить счёт' : 'Закрыть расчёт'">
            <form method="post" action="/garage/cars/{{ $n }}/settle" class="flex flex-col gap-3" @if ($car->manager) data-controller="commission reveal" data-commission-margin-value="{{ $s['profit'] }}" data-commission-base-value="{{ $car->sold_price - $s['manager_costs'] }}" @endif>
                @csrf
                <div class="list">
                    <div class="row justify-between"><span>Продана за</span><span class="nums">{{ Money::rub($car->sold_price) }}</span></div>
                    <div class="row justify-between"><span>Вложено</span><span class="nums">{{ Money::exact($s['invested']) }}</span></div>
                    <div class="row justify-between"><span>Прибыль</span><span class="nums font-semibold {{ $s['profit'] < 0 ? 'text-danger' : '' }}">{{ Money::exact($s['profit']) }}</span></div>
                </div>
                @if ($car->manager)
                    {{-- Ползунком, суммой или процентом от прибыли (16.11.2026, `x-deal.reward-split`). --}}
                    <x-deal.reward-split name="commission" id="fee" :value="old('commission', $car->commission)" :margin="$s['profit'] === null ? null : (int) round($s['profit'])"
                        :hidden="['data-commission-target' => 'amount']" data-commission-target="display" data-action="input->commission#input"/>
                    <div class="list">
                        <div class="row justify-between"><span>Нам остаётся</span><span class="nums font-semibold" data-commission-target="ours">{{ Money::exact($s['ours']) }}</span></div>
                        <div class="row justify-between"><span>К оплате</span><span class="nums font-semibold" data-commission-target="due">{{ Money::exact(max(0, $s['due'])) }}</span></div>
                        @error('party_id')<p class="field-error px-4 pb-3">{{ $message }}</p>@enderror
                    </div>
                @endif
                @if ($car->manager && ! $car->managerPaidSupplier())
                    {{-- Кто платит — как у сделки: менеджер или его покупатель, сумма та же. --}}
                    <div class="flex flex-col gap-3">
                        <div class="flex flex-wrap gap-2">
                            <label class="choice"><input type="radio" name="payer" value="manager" @checked(old('payer', 'manager') === 'manager') data-action="reveal#pick"><span>Платит менеджер</span></label>
                            <label class="choice"><input type="radio" name="payer" value="buyer" @checked(old('payer') === 'buyer') data-action="reveal#pick"><span>Платит покупатель</span></label>
                        </div>
                        <div data-reveal-target="pane" data-reveal-key="buyer" hidden>
                            <div class="fields">
                                <x-ui.field name="party_id" label="Покупатель" :options="$parties" value="new"/>
                                <x-ui.field name="party_name" label="Название или ФИО" :value="old('party_name', $car->buyer_name)"/>
                                <x-ui.field name="party_kind" label="Кто" :options="collect(\App\Billing\PartyKind::cases())->mapWithKeys(fn ($k) => [$k->value => $k->label()])" value="person"/>
                                <x-ui.field name="party_inn" label="ИНН"/>
                                <x-ui.field name="party_phone" label="Телефон" :value="old('party_phone', $car->buyer_phone)"/>
                            </div>
                        </div>
                    </div>
                @endif
                <x-ui.sheet-foot type="submit" variant="primary">{{ $car->manager ? 'Выставить' : 'Закрыть расчёт' }}</x-ui.sheet-foot>
            </form>
        </x-ui.sheet>
    </div>
@endif


@if ($staff && $unpaid)
    <div {!! $wrap('paid') !!}>
        <x-ui.sheet id="paid" title="Деньги пришли">
            {{-- Та же форма, что «Деньги пришли» у счёта и в шаге сделки (28.10.2026): откуда, номер и скан платёжки. --}}
            <x-billing.pay-form :invoice="$current" :action="'/garage/cars/'.$n.'/payments'" :source="$current->claims()->first()?->source->value" sheet/>
        </x-ui.sheet>
    </div>
@endif

@if ($staff && ($buyerCar ?? false) && $car->state !== \App\Garage\CarState::Settled && ! ($buyerPaid ?? false))
    {{-- Цена покупателю (20.10.2026): правим, пока он не платил; счёт на прежнюю гаснет и встаёт на новую сам. --}}
    <div {!! $wrap('buyer-price') !!}>
        <x-ui.sheet id="buyer-price-{{ $n }}" title="Цена покупателю">
            <form method="post" action="/garage/cars/{{ $n }}/buyer-price" class="flex flex-col gap-3">
                @csrf @method('put')
                <div class="fields">
                    <x-ui.field name="buyer_price" label="Цена, ₽" :value="old('buyer_price', $car->buyer_price)" :id="'buyer-price-'.$n.'-field'" required/>
                </div>
                <x-ui.sheet-foot type="submit" variant="primary">Сохранить</x-ui.sheet-foot>
            </form>
        </x-ui.sheet>
    </div>
@endif

@if (($buyerCar ?? $asBuyer ?? false) && $car->deal && $car->state !== \App\Garage\CarState::Settled)
    {{-- Цена продажи и вознаграждение покупателя с гаражом (27.11.2026, владелец: «продаёт за 900, вложил 123 — отнимаем, 50 его
         комиссия — отнимаем, остальное по счёту на ПРАЙМ»): счёт и договор его клиенту — цена минус его расходы и вознаграждение
         (`Deal::holderNet`, `SetGarageSalePrice`). --}}
    @php $holderSpent = $car->extraSpent(\App\Garage\Payer::Manager); @endphp
    <div {!! $wrap('sale-price') !!}>
        <x-ui.sheet id="sale-price-{{ $n }}" title="Цена продажи">
            <form method="post" action="/garage/cars/{{ $n }}/sale-price" class="flex flex-col gap-3">
                @csrf @method('put')
                <div class="fields">
                    <x-ui.field name="sale_price" label="Цена продажи, ₽" :value="old('sale_price', $car->deal->amount)" :id="'sale-price-'.$n.'-field'" required/>
                    <x-ui.field name="sale_commission" :label="($asBuyer ?? false) ? 'Ваше вознаграждение, ₽' : 'Вознаграждение покупателя, ₽'" :value="old('sale_commission', $car->deal->commission)" :id="'sale-commission-'.$n.'-field'" digits required/>
                </div>
                <p class="px-1 text-sm text-ink-muted">По счёту ПРАЙМ: цена продажи минус {{ ($asBuyer ?? false) ? 'ваши расходы' : 'расходы покупателя' }}@if ($holderSpent > 0) ({{ \App\Support\Money::rub($holderSpent) }})@endif и вознаграждение</p>
                <x-ui.sheet-foot type="submit" variant="primary">Сохранить</x-ui.sheet-foot>
            </form>
        </x-ui.sheet>
    </div>
@endif

@if ($asBuyer ?? false)
    @if ($holderDue)
        {{-- Покупатель с гаражом платит свой счёт сделки — той же шторкой, что в «Оплатах» кабинета. --}}
        <div {!! $wrap('pay') !!}>
            <x-billing.pay-sheet :invoices="collect([$holderInvoice])" :action="'/account/money/deals/'.$car->deal_id.'/pay'" pdf="/account/invoices/{id}/pdf" :open="$errors->any()"/>
        </div>
    @endif
@elseif (! $staff && $unpaid && $car->invoice_to !== 'buyer' && $current->remaining() - $current->claimed() > 0)
    <div {!! $wrap('pay') !!}>
        <x-billing.pay-sheet :invoices="collect([$invoice])" :action="'/garage/cars/'.$n.'/checkout'" :pdf="'/garage/cars/'.$n.'/invoice/pdf'" :buyers="\App\Support\People::buyersOf($user)" :open="$errors->any()" :other-name="$car->buyer_name"/>
    </div>
@endif
