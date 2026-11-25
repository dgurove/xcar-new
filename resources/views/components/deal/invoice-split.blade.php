{{-- Сколько на счёт за ТС ПРАЙМ / Р.О.С., остальное — подбором по ссылке (25.11.2026, владелец выбрал из макетов «Б»: второй полосой под
     вознаграждением, «остаток в счёт | ссылкой»; по 7806/046/03674/26: «Р.О.С. 1 700 000, подбор ссылкой 160 000, менеджеру 40 000»).
     Остаток — цена продажи минус закупочная, вознаграждение и наличные; заливка слева — что легло в счёт сверх закупочной. Поля — счёт
     целиком (`car_price`, `Deal::carPrice`) и ссылкой: любое двигает другое и полосу (`invoice_split_controller`). Цену, вознаграждение
     и наличные меняют в той же форме: вписанный счёт стоит, ссылка забирает разницу; не вписанный идёт за умолчанием (Р.О.С. —
     закупочная, ПРАЙМ — всё в счёте). У Совкомбанка ссылка из нуля — «Вы уверены?» (`Warnings::SELECTION_APART_SOVCOM`). --}}
@props(['deal', 'id'])
@php
    use App\Offers\Warnings;
    use App\Support\Money;
    $cost = (int) $deal->cost;
    $car = old('car_price') !== null ? (int) Money::rubles(old('car_price')) : (int) $deal->carPrice();
    $cap = $deal->carCap();
    $sovcom = $deal->offer->vendor?->parser === \App\Vendors\Parser::Sovcombank;
@endphp
<div class="flex flex-col gap-1.5 @error('car_price') field-invalid @enderror" data-controller="invoice-split"
    data-invoice-split-cost-value="{{ $cost }}" data-invoice-split-car-value="{{ $car }}" data-invoice-split-ros-value="{{ $deal->seller() === \App\Billing\Seller::Ros ? 'true' : 'false' }}"
    data-invoice-split-set-value="{{ $deal->contract?->car_price !== null || old('car_price') !== null ? 'true' : 'false' }}"
    @if ($sovcom) data-invoice-split-ask-value="{{ Warnings::SELECTION_APART_SOVCOM }}" data-invoice-split-title-value="{{ Warnings::TITLE }}"
        data-invoice-split-ok-value="Да, отдельной ссылкой" data-invoice-split-cancel-value="{{ Warnings::CANCEL }}" @endif>
    <span class="field-label">Остаток <span class="nums" data-invoice-split-target="rest">{{ Money::nums(max(0, $cap - $cost)) }}</span>: на счёт или по ссылке</span>
    <div class="reward-bar reward-bar--car" data-invoice-split-target="bar" style="--p: {{ $cap > $cost ? round((max($cost, min($cap, $car)) - $cost) / ($cap - $cost) * 100, 2) : 0 }}%" @if ($cap <= $cost) hidden @endif>
        <div class="reward-bar-text" aria-hidden="true"><span data-invoice-split-target="carText"></span><span data-invoice-split-target="linkText"></span></div>
        <div class="reward-bar-fill" aria-hidden="true"><div class="reward-bar-text"><span data-invoice-split-target="carText"></span><span data-invoice-split-target="linkText"></span></div></div>
        <input type="range" min="0" max="100" step="1" value="0" class="reward-bar-range" aria-label="Сколько остатка на счёт"
            data-invoice-split-target="range" data-action="input->invoice-split#slide change->invoice-split#check">
    </div>
    <div class="grid grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)] gap-2">
        <div class="field">
            <label for="{{ $id }}-car" class="field-label">На счёт {{ $deal->schemeOf()->short() }}, ₽</label>
            <input id="{{ $id }}-car" type="text" inputmode="decimal" autocomplete="off" class="field-input nums" value="{{ Money::nums($car) }}"
                data-controller="digits" data-digits-decimals-value="0" data-invoice-split-target="car" data-action="input->digits#format input->invoice-split#typedCar change->invoice-split#check">
        </div>
        <div class="field">
            <label for="{{ $id }}-link" class="field-label">По ссылке, ₽</label>
            <input id="{{ $id }}-link" type="text" inputmode="decimal" autocomplete="off" class="field-input nums" value="{{ Money::nums(max(0, $cap - $car)) }}"
                data-controller="digits" data-digits-decimals-value="0" data-invoice-split-target="link" data-action="input->digits#format input->invoice-split#typedLink change->invoice-split#check">
        </div>
    </div>
    <input type="hidden" name="car_price" value="{{ $car }}" data-invoice-split-target="hidden">
    @error('car_price')<p class="field-error">{{ $message }}</p>@enderror
</div>
