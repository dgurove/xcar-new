{{-- Шторки экрана машины. Каждую открывает событие «имя:open» — с главной кнопки или из «⋯». --}}
@php
    use App\Support\Money;
    $wrap = fn (string $name) => 'data-controller="sheet" data-action="'.$name.':open@window->sheet#open" class="contents"';
    $sum = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, ',', ''), '0'), ',');
@endphp

@if ($canAdd)
    <div {!! $wrap('cost-new') !!}>
        <x-ui.sheet id="cost-new" title="Записать расход" :open="$errors->has('title') || $errors->has('amount') && ! $unpaid">
            <form method="post" action="/cars/{{ $n }}/costs" class="flex flex-col gap-4">
                @csrf
                @include('garage.cars.cost-fields', ['cost' => null])
                <x-ui.button type="submit" variant="primary" block>Записать</x-ui.button>
            </form>
        </x-ui.sheet>
    </div>
@endif

@if ($staff && ! $car->isSold())
    <div {!! $wrap('sold') !!}>
        <x-ui.sheet id="sold" title="Продана" :open="$errors->has('sold_price')">
            <form method="post" action="/cars/{{ $n }}/sold" class="flex flex-col gap-4">
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
        <x-ui.sheet id="settle" :title="$car->manager ? 'Выставить счёт' : 'Закрыть расчёт'" :open="$errors->has('commission')">
            <form method="post" action="/cars/{{ $n }}/settle" class="flex flex-col gap-4" @if ($car->manager) data-controller="commission" data-commission-margin-value="{{ $s['profit'] }}" data-commission-base-value="{{ $car->sold_price - $s['manager_costs'] }}" @endif>
                @csrf
                <div class="list">
                    <div class="row justify-between"><span>Продана за</span><span class="nums">{{ Money::rub($car->sold_price) }}</span></div>
                    <div class="row justify-between"><span>Вложено</span><span class="nums">{{ Money::exact($s['invested']) }}</span></div>
                    <div class="row justify-between"><span>Прибыль</span><span class="nums font-semibold {{ $s['profit'] < 0 ? 'text-danger' : '' }}">{{ Money::exact($s['profit']) }}</span></div>
                </div>
                @if ($car->manager)
                    <div class="field">
                        <label for="fee" class="field-label">Вознаграждение менеджеру, ₽</label>
                        <input type="hidden" name="commission" data-commission-target="amount" value="{{ old('commission', $car->commission) }}">
                        <input id="fee" type="text" inputmode="numeric" class="field-input nums text-lg" data-commission-target="display" data-action="input->commission#input" value="{{ $car->commission ? Money::nums($car->commission) : '' }}" autocomplete="off" placeholder="0">
                        @error('commission')<p class="field-error">{{ $message }}</p>@enderror
                    </div>
                    <div class="list">
                        <div class="row justify-between"><span>Нам остаётся</span><span class="nums font-semibold" data-commission-target="ours">{{ Money::exact($s['ours']) }}</span></div>
                        <div class="row justify-between"><span data-commission-target="dueLabel" data-positive="Менеджер отдаёт нам" data-negative="Отдаём менеджеру">Менеджер отдаёт нам</span><span class="nums font-semibold" data-commission-target="due">{{ Money::exact(abs($s['due'])) }}</span></div>
                    </div>
                    <x-ui.check name="vat" :checked="$offer->prices_include_vat">С НДС</x-ui.check>
                @endif
                <x-ui.button type="submit" variant="primary" block>{{ $car->manager ? 'Выставить' : 'Закрыть расчёт' }}</x-ui.button>
            </form>
        </x-ui.sheet>
    </div>
@endif

@if ($staff && $unpaid)
    <div {!! $wrap('paid') !!}>
        <x-ui.sheet id="paid" :title="$invoice->isOwed() ? 'Выплатили менеджеру' : 'Деньги пришли'" :open="$errors->has('amount')">
            <form method="post" action="/cars/{{ $n }}/payments" class="flex flex-col gap-4">
                @csrf
                <x-ui.field name="amount" label="Сколько, ₽" :value="$sum($invoice->remaining())" inputmode="decimal"/>
                <x-ui.field name="paid_at" label="Когда" type="date" :value="now()->toDateString()" max="{{ now()->toDateString() }}"/>
                <x-ui.button type="submit" variant="primary" block>Записать</x-ui.button>
            </form>
        </x-ui.sheet>
    </div>
@endif

@if (! $staff && $unpaid && ! $invoice->isOwed())
    <div {!! $wrap('claim') !!}>
        <x-ui.sheet id="claim" title="Сообщить об оплате" :open="$errors->has('slip') || $errors->has('amount')">
            <form method="post" action="/cars/{{ $n }}/claims" class="flex flex-col gap-4" enctype="multipart/form-data">
                @csrf
                <x-ui.field name="amount" label="Сколько заплатили, ₽" :value="$sum($invoice->remaining() - $invoice->claimed())" inputmode="decimal"/>
                <x-ui.field name="paid_at" label="Когда" type="date" :value="now()->toDateString()" max="{{ now()->toDateString() }}"/>
                <x-ui.field name="slip" label="Фото платёжки" type="file" accept="image/*,application/pdf"/>
                <x-ui.button type="submit" variant="primary" block>Отправить</x-ui.button>
            </form>
        </x-ui.sheet>
    </div>
@endif
