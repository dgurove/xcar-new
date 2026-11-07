{{-- «Хранение» — своё парковки рядом с блоками машины (`x-car.card`, тот же вид): оценочная стоимость (по ней считают
     хранение и закупочную продажи) и своя ставка у принятой ТС — пусто, ставка по тарифу (он подсказкой). --}}
@php
    use App\Support\Money;
    $fold ??= null;
    $vehicle ??= null;
    // Новая ТС (заявка руками, разбор письма) — значение из письма.
    $value = $vehicle ? $vehicle->value : ($values['value'] ?? null);
    // Своя ставка — деньги: приёмщику (`User::parkMoney`, 30.10.2026) только оценочная стоимость.
    $rate = $vehicle?->accepted_at && (auth()->user()?->parkMoney() ?? false);
    if ($fold && $errors->hasAny(['value', 'storage_rate'])) {
        $fold = false;
    }
    // Спор об оценочной (`Cars\Sources\Variants`) — меткой у поля.
    $variant = \App\Cars\Sources\Variants::visible($vehicle, ['value'])['value'] ?? null;
    // Оценочные из источников — под полем (и выбранные раньше, и отклонённые: `Values::known`).
    $valueKnown = $vehicle && \App\Cars\Sources\Variants::can($vehicle) ? collect(\App\Cars\Sources\Values::known($vehicle->car_id)['value'] ?? []) : collect();
    $valueRest = $valueKnown->reject(fn ($i) => (int) ($i['write']['value'] ?? 0) === (int) $value);
    $valueSources = $valueRest->isEmpty() ? [] : [
        ...($value !== null ? [['value' => (string) $value, 'label' => Money::rub((int) $value), 'from' => $valueKnown->first(fn ($i) => (int) ($i['write']['value'] ?? 0) === (int) $value)['from'] ?? 'сейчас', 'now' => true]] : []),
        ...$valueRest->map(fn ($i) => ['value' => (string) $i['write']['value'], 'label' => $i['label'], 'from' => $i['from'], 'now' => false])->values()->all(),
    ];
    $empty = $value === null && ! $variant && ($vehicle?->storage_rate === null || ! $rate);
@endphp
<x-car.card title="Хранение" :fold="$fold" :empty="$empty">
    <x-slot:top>
        <x-car.variants :lane="$vehicle" :variants="array_filter(['value' => $variant])"/>
    </x-slot:top>
    <x-slot:view>
        @unless ($empty)
            <div class="grid grid-cols-2 gap-3 !gap-y-2.5 leading-snug @4xl:grid-cols-3">
                @if ($value !== null || $variant)
                    <div class="min-w-0">
                        <div class="field-label">Оценочная стоимость</div>
                        @if ($value !== null)<div class="mt-0.5 nums">{{ Money::rub($value) }}</div>@endif
                        <x-car.variant-hint :v="$variant" :car="$vehicle?->car_id"/>
                    </div>
                @endif
                @if ($rate && $vehicle->storage_rate !== null)<div class="min-w-0"><div class="field-label">Своя ставка</div><div class="mt-0.5 nums">{{ Money::nums((int) round($vehicle->storage_rate)) }} ₽/сут</div></div>@endif
            </div>
        @endunless
    </x-slot:view>
    <div class="grid grid-cols-2 gap-3 @4xl:grid-cols-3">
        <x-ui.field digits name="value" label="Оценочная стоимость, ₽" :value="$value">
            <x-slot:hint><x-car.variant-hint :v="$variant" :car="$vehicle?->car_id"/><x-car.source-values :items="$valueSources"/></x-slot:hint>
        </x-ui.field>
        @if ($rate)
            <x-ui.field name="storage_rate" label="Своя ставка, ₽/сут"
                :value="$vehicle->storage_rate !== null ? Money::nums((int) round($vehicle->storage_rate)) : null" :placeholder="\App\Park\VehicleRate::hint($vehicle)"/>
        @endif
    </div>
</x-car.card>
