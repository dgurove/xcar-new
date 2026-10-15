{{-- Ставка машины — кусок фразы с шторкой (12.10.2026, владелец: «единоразово: для этой машины ставка такая»; `Park\VehicleRate`):
     «300 ₽/сут», своя — «300 ₽/сут, своя», нет — оранжевое «ставки нет». Нажатие — шторка «Ставка»: тип ТС, своя ставка
     (пусто — по тарифу, тариф подсказкой в поле), почему своя, оценочная. Править — `canManagePark`, остальным текст.
     Шторка со своей формой: ставить только вне другой формы (форма приёма шлёт те же поля сама). --}}
@props(['vehicle', 'key' => 'card'])
@php
    use App\Park\VehicleRate;
    $shown = VehicleRate::shown($vehicle);
    $can = auth()->user()?->canManagePark() ?? false;
    $label = $shown['text'] ? $shown['text'].($shown['own'] ? ', своя' : '') : 'ставки нет';
    $id = 'rate-'.$vehicle->id.'-'.$key;
@endphp
@if (! $can)
    <span {{ $attributes->class(['nums', 'text-urgent' => ! $shown['text']]) }}>{{ $label }}</span>
@else
<span class="contents" data-controller="sheet">
    <button type="button" {{ $attributes->class(['nums underline decoration-dotted decoration-ink-dim underline-offset-4', 'text-urgent' => ! $shown['text']]) }} data-action="sheet#open">{{ $label }}</button>
    <x-ui.sheet :id="$id" title="Ставка">
        <form method="post" action="/cars/{{ $vehicle->id }}/rate" class="flex flex-col gap-3">
            @csrf
            <div class="fields">
                <x-ui.field name="category" :id="$id.'-category'" label="Тип ТС" :options="collect(\App\Cars\Category::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()])->all()" placeholder="—" :value="$vehicle->category?->value"/>
                <x-ui.field name="storage_rate" :id="$id.'-rate'" label="Своя ставка, ₽/сут"
                    :value="$vehicle->storage_rate !== null ? \App\Support\Money::nums((int) round($vehicle->storage_rate)) : null" :placeholder="\App\Park\VehicleRate::hint($vehicle)"/>
                <x-ui.field name="storage_rate_note" :id="$id.'-note'" label="Почему своя" :value="$vehicle->storage_rate_note" maxlength="120"/>
                <x-ui.field digits name="value" :id="$id.'-value'" label="Оценочная, ₽" :value="$vehicle->value ? \App\Support\Money::nums($vehicle->value) : null"/>
            </div>
            <div class="sheet-foot"><x-ui.button block>Сохранить</x-ui.button></div>
        </form>
    </x-ui.sheet>
</span>
@endif
