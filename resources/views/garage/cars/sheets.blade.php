{{-- Шторки экрана машины. Каждую открывает событие «имя:open» — с главной кнопки или из «⋯». --}}
@php
    use App\Support\Money;
    $wrap = fn (string $name) => 'data-controller="sheet" data-action="'.$name.':open@window->sheet#open" class="contents"';
    // Сумма в поле — разрядами, копейки двумя цифрами: «15 000,50», а не «15000,5».
    $sum = fn ($v) => Money::nums($v, fmod((float) $v, 1) ? 2 : 0);
    // Плательщик по продаже — любой контрагент или новый, как в счёте сделки.
    $parties = $staff && $car->state === \App\Garage\CarState::Sold && ! $invoice
        ? \App\Billing\Party::where('is_self', false)->orderBy('name')->pluck('name', 'id')->prepend('Новый плательщик', 'new') : collect();
@endphp

@if ($canAdd)
    <div {!! $wrap('cost-new') !!}>
        <x-ui.sheet id="cost-new" title="Записать расход" :open="$errors->has('title') || $errors->has('amount') && ! $unpaid">
            <form method="post" action="/garage/cars/{{ $n }}/costs" class="flex flex-col gap-4">
                @csrf
                @include('garage.cars.cost-fields', ['cost' => null])
                <x-ui.button type="submit" variant="primary" block>Записать</x-ui.button>
            </form>
        </x-ui.sheet>
    </div>
@endif

@if (! $staff && $car->state === \App\Garage\CarState::Selling)
    {{-- «Продаю»: только цена — её не отклоняем, вознаграждение и счёт дальше наши. --}}
    <div {!! $wrap('selling') !!}>
        <x-ui.sheet id="selling" title="Продаю" :open="$errors->has('sold_price')">
            <form method="post" action="/garage/cars/{{ $n }}/sold" class="flex flex-col gap-4">
                @csrf
                <x-ui.field name="sold_price" label="За сколько, ₽" :value="old('sold_price')" inputmode="numeric" data-controller="digits" data-action="input->digits#format" required/>
                <x-ui.button type="submit" variant="primary" block>Продаю</x-ui.button>
            </form>
        </x-ui.sheet>
    </div>
@endif

@if ($staff && $car->state->isWorking())
    <div {!! $wrap('sold') !!}>
        <x-ui.sheet id="sold" title="Продана" :open="$errors->has('sold_price')">
            <form method="post" action="/garage/cars/{{ $n }}/sold" class="flex flex-col gap-4">
                @csrf
                <x-ui.field name="sold_price" label="За сколько, ₽" :value="old('sold_price')"/>
                <x-ui.field name="sold_at" label="Когда" type="date" :value="now()->toDateString()" max="{{ now()->toDateString() }}"/>
                <x-ui.field name="buyer_name" label="Покупатель" :value="old('buyer_name')" placeholder="Кому продал"/>
                <x-ui.field name="buyer_phone" label="Телефон покупателя" :value="old('buyer_phone')"/>
                <x-ui.button type="submit" variant="primary" block>Продана</x-ui.button>
            </form>
        </x-ui.sheet>
    </div>
@endif

@if ($staff && $car->state === \App\Garage\CarState::Sold && ! $invoice)
    {{-- Вознаграждение вводится здесь же: суммы ниже пересчитываются на месте. Отдаёт — цена минус расходы менеджера
         минус вознаграждение; ушло в минус — отдаём мы, документ станет обязательством перед ним. --}}
    <div {!! $wrap('settle') !!}>
        <x-ui.sheet id="settle" :title="$car->manager ? 'Выставить счёт' : 'Закрыть расчёт'" :open="$errors->hasAny(['commission', 'party_id', 'party_name'])">
            <form method="post" action="/garage/cars/{{ $n }}/settle" class="flex flex-col gap-4" @if ($car->manager) data-controller="commission reveal" data-commission-margin-value="{{ $s['profit'] }}" data-commission-base-value="{{ $car->sold_price - $s['manager_costs'] }}" data-commission-spent-value="{{ $s['manager_costs'] }}" @endif>
                @csrf
                <div class="list">
                    <div class="row justify-between"><span>Продана за</span><span class="nums">{{ Money::rub($car->sold_price) }}</span></div>
                    <div class="row justify-between"><span>Вложено</span><span class="nums">{{ Money::exact($s['invested']) }}</span></div>
                    <div class="row justify-between"><span>Прибыль</span><span class="nums font-semibold {{ $s['profit'] < 0 ? 'text-danger' : '' }}">{{ Money::exact($s['profit']) }}</span></div>
                </div>
                @if ($car->manager && ! $car->managerPaidSupplier())
                    {{-- Кто платит — как у сделки: менеджер (его деньги покупателя у него на руках) или его покупатель
                         (счёт на всю цену, менеджеру после оплаты — его расходы и вознаграждение). --}}
                    <div class="flex flex-col gap-4">
                        <div class="flex flex-wrap gap-2">
                            <label class="choice"><input type="radio" name="payer" value="manager" @checked(old('payer', 'manager') === 'manager') data-action="reveal#pick"><span>Платит менеджер</span></label>
                            <label class="choice"><input type="radio" name="payer" value="buyer" @checked(old('payer') === 'buyer') data-action="reveal#pick"><span>Платит покупатель</span></label>
                        </div>
                        <div class="flex flex-col gap-4" data-reveal-target="pane" data-reveal-key="buyer" hidden>
                            <x-ui.field name="party_id" label="Плательщик" :options="$parties" value="new"/>
                            <x-ui.field name="party_name" label="Название или ФИО" :value="old('party_name', $car->buyer_name)"/>
                            <div class="grid grid-cols-2 gap-3">
                                <x-ui.field name="party_kind" label="Кто" :options="collect(\App\Billing\PartyKind::cases())->mapWithKeys(fn ($k) => [$k->value => $k->label()])" value="person"/>
                                <x-ui.field name="party_inn" label="ИНН"/>
                            </div>
                            <x-ui.field name="party_phone" label="Телефон" :value="old('party_phone', $car->buyer_phone)"/>
                        </div>
                    </div>
                @endif
                @if ($car->manager)
                    <div class="field">
                        <label for="fee" class="field-label">Вознаграждение менеджеру, ₽</label>
                        <input type="hidden" name="commission" data-commission-target="amount" value="{{ old('commission', $car->commission) }}">
                        <input id="fee" type="text" class="field-input nums text-lg" data-commission-target="display" data-action="input->commission#input" value="{{ $car->commission ? Money::nums($car->commission) : '' }}" autocomplete="off" placeholder="0">
                        @error('commission')<p class="field-error">{{ $message }}</p>@enderror
                    </div>
                    <div class="list">
                        <div class="row justify-between"><span>Нам остаётся</span><span class="nums font-semibold" data-commission-target="ours">{{ Money::exact($s['ours']) }}</span></div>
                        {{-- Платит менеджер — сколько он отдаёт нам; платит покупатель — сколько выплатим менеджеру после оплаты. --}}
                        <div class="row justify-between" data-reveal-target="pane" data-reveal-key="manager"><span data-commission-target="dueLabel" data-positive="Менеджер отдаёт нам" data-negative="Отдаём менеджеру">Менеджер отдаёт нам</span><span class="nums font-semibold" data-commission-target="due">{{ Money::exact(abs($s['due'])) }}</span></div>
                        <div class="row justify-between" data-reveal-target="pane" data-reveal-key="buyer" hidden><span>Выплатим менеджеру</span><span class="nums font-semibold" data-commission-target="payout">{{ Money::exact($s['payout']) }}</span></div>
                        @error('party_id')<p class="field-error px-4 pb-3">{{ $message }}</p>@enderror
                    </div>
                @endif
                <x-ui.button type="submit" variant="primary" block>{{ $car->manager ? 'Выставить' : 'Закрыть расчёт' }}</x-ui.button>
            </form>
        </x-ui.sheet>
    </div>
@endif

@if ($staff && $car->awaitsPayout())
    {{-- Покупатель заплатил, а выплаты нет: аннулировали или оплата пришла сама. Вознаграждение можно поправить. --}}
    <div {!! $wrap('payout') !!}>
        <x-ui.sheet id="payout" title="Выплата менеджеру" :open="$errors->has('car')">
            <form method="post" action="/garage/cars/{{ $n }}/payout" class="flex flex-col gap-4" data-controller="commission" data-commission-spent-value="{{ $s['manager_costs'] }}">
                @csrf
                <div class="field">
                    <label for="payout-fee" class="field-label">Вознаграждение менеджеру, ₽</label>
                    <input type="hidden" name="commission" data-commission-target="amount" value="{{ $car->commission }}">
                    <input id="payout-fee" type="text" class="field-input nums text-lg" data-commission-target="display" data-action="input->commission#input" value="{{ $car->commission ? Money::nums($car->commission) : '' }}" autocomplete="off" placeholder="0">
                </div>
                <div class="list">
                    <div class="row justify-between"><span>Его расходы</span><span class="nums">{{ Money::exact($s['manager_costs']) }}</span></div>
                    <div class="row justify-between"><span>Выплатим</span><span class="nums font-semibold" data-commission-target="payout">{{ Money::exact($s['payout']) }}</span></div>
                </div>
                <x-ui.button type="submit" variant="primary" block>Выплата менеджеру</x-ui.button>
            </form>
        </x-ui.sheet>
    </div>
@endif

@if ($staff && $unpaid)
    <div {!! $wrap('paid') !!}>
        <x-ui.sheet id="paid" :title="$current->isOwed() ? 'Выплатили менеджеру' : 'Деньги пришли'" :open="$errors->has('amount')">
            <form method="post" action="/garage/cars/{{ $n }}/payments" class="flex flex-col gap-4">
                @csrf
                <x-ui.field name="amount" label="Сколько, ₽" :value="$sum($current->remaining())" data-controller="digits" data-digits-decimals-value="2" data-action="input->digits#format"/>
                <x-ui.field name="paid_at" label="Когда" type="date" :value="now()->toDateString()" max="{{ now()->toDateString() }}"/>
                <x-ui.button type="submit" variant="primary" block>Записать</x-ui.button>
            </form>
        </x-ui.sheet>
    </div>
@endif

@if (! $staff && $unpaid && ! $current->isOwed() && $car->invoice_to !== 'buyer' && $current->remaining() - $current->claimed() > 0)
    <div {!! $wrap('pay') !!}>
        <x-billing.pay-sheet :invoices="collect([$invoice])" :action="'/garage/cars/'.$n.'/checkout'" :pdf="'/garage/cars/'.$n.'/invoice/pdf'" :buyers="$user->buyers()->orderBy('name')->get()" :open="$errors->any()" :other-name="$car->buyer_name" :other-phone="$car->buyer_phone"/>
    </div>
@endif
