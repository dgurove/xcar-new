{{-- Шторка «Хранение» по нажатию на сумму в полоске срока (09.11.2026, D3 владельца). Сверху расчёт строками: за сколько
     дней сколько, не выставлено (ведёт в счёт), выставлено по какой день, долг. Ниже — как считать: у выданной сегментом
     «По ставке | Своя сумма» (владелец: «иногда хотят не ставку редактировать, а просто саму сумму — ставка изменилась, пока
     стояла, им проще итоговую указать»), у стоящей — только ставка. Ставка — те же поля, что в `x-park.rate`; сохраняет
     `VehicleController::rate` (`mode=rate` снимает свою сумму, `mode=total` ставит). --}}
@props(['id', 'vehicle', 'total', 'debt' => 0, 'unbilled' => 0])
@php
    use App\Support\Money;
    $out = $vehicle->state === \App\Park\VehicleState::Released;
    $own = $out && $vehicle->storage_total !== null;
    $mode = old('_sheet') === $id ? old('mode', $own ? 'total' : 'rate') : ($own ? 'total' : 'rate');
    $days = (int) ($total['days'] ?? 0);
    $rate = \App\Park\VehicleRate::shown($vehicle);
@endphp
<x-ui.sheet :id="$id" title="Хранение">
    <div class="list">
        <div class="row justify-between gap-3">
            <span class="text-ink-muted">{{ $days }} {{ \App\Support\Plural::of($days, ['день', 'дня', 'дней']) }}{{ $own ? ', своя сумма' : ($rate['text'] ? ' по '.$rate['text'] : '') }}</span>
            <span class="nums font-bold">{{ Money::rub($total['amount']) }}</span>
        </div>
        @if ($unbilled > 0)
            <a href="/cars/{{ $vehicle->id }}/invoices/new" data-turbo-frame="_top" class="row justify-between gap-3">
                <span>Не выставлено</span>
                <span class="flex items-center gap-1"><span class="nums">{{ Money::rub($unbilled) }}</span><x-ui.icon name="chevron-right" class="size-4 text-ink-dim"/></span>
            </a>
        @endif
        @if ($vehicle->storage_billed_until)
            <div class="row justify-between gap-3"><span class="text-ink-muted">Выставлено по</span><span class="nums">{{ \App\Support\Ago::day($vehicle->storage_billed_until) }}</span></div>
        @endif
        @if ($debt > 0)
            <a href="/cars/{{ $vehicle->id }}" data-turbo-frame="_top" class="row justify-between gap-3">
                <span class="text-danger">Долг</span>
                <span class="flex items-center gap-1"><span class="nums text-danger">{{ Money::rub($debt) }}</span><x-ui.icon name="chevron-right" class="size-4 text-ink-dim"/></span>
            </a>
        @endif
    </div>
    <form method="post" action="/cars/{{ $vehicle->id }}/rate" class="mt-4 flex flex-col gap-3" data-controller="reveal">
        @csrf
        @if ($out)
            <x-ui.segment name="mode" :options="['rate' => 'По ставке', 'total' => 'Своя сумма']" :value="$mode" pick/>
        @else
            <input type="hidden" name="mode" value="rate">
        @endif
        <div class="fields" data-reveal-target="pane" data-reveal-key="rate" @if ($mode !== 'rate') hidden @endif>
            <x-ui.field name="storage_rate" :id="$id.'-rate'" label="Своя ставка, ₽/д"
                :value="$vehicle->storage_rate !== null ? Money::nums((int) round($vehicle->storage_rate)) : null" :placeholder="\App\Park\VehicleRate::hint($vehicle)"/>
            <x-ui.field name="category" :id="$id.'-category'" label="Тип ТС" :options="collect(\App\Cars\Category::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()])->all()" placeholder="—" :value="$vehicle->category?->value"/>
            <x-ui.field digits name="value" :id="$id.'-value'" label="Оценочная, ₽" :value="$vehicle->value ? Money::nums($vehicle->value) : null"/>
        </div>
        @if ($out)
            <div class="fields" data-reveal-target="pane" data-reveal-key="total" @if ($mode !== 'total') hidden @endif>
                <x-ui.field digits name="storage_total" :id="$id.'-total'" label="Сумма за {{ $days }} {{ \App\Support\Plural::of($days, ['день', 'дня', 'дней']) }}, ₽"
                    :value="$own ? Money::nums((int) round($vehicle->storage_total)) : null" :placeholder="Money::nums((int) round($total['amount']))"/>
            </div>
        @endif
        <div class="fields">
            <x-ui.field name="storage_rate_note" :id="$id.'-note'" label="Почему" :value="$vehicle->storage_rate_note" maxlength="120"/>
        </div>
        <x-ui.sheet-foot>Сохранить</x-ui.sheet-foot>
    </form>
</x-ui.sheet>
