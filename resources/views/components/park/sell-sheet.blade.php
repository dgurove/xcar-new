{{-- «В продажу» на парковке (13.10.2026, владелец: «пусть пишет оценочную и закупочную с возможностью отредактировать; для
     некоторых закупочная равна оценочной, для некоторых нет — админу важно понимать, что это корректно»). Оценочная —
     поле; закупочная — «По правилу» (`Pricing::floorFrom`: +50 000 ₽ / +10% / +200 000 ₽), «Равна оценочной» или «Своя»;
     суммы и кнопка «Выставить за …» пересчитывает `sale_floor_controller`. Связанное предложение уже было — его закупочная
     и режим по ней (`Pricing::floorMode`). --}}
@props(['vehicle', 'id'])
@php
    use App\Offers\Pricing;
    use App\Support\Money;
    $value = $vehicle->value ? (int) $vehicle->value : null;
    $floor = $vehicle->offer?->floor_price ? (int) $vehicle->offer->floor_price : null;
    $mode = old('floor_mode', Pricing::floorMode($floor, $value));
@endphp
<x-ui.sheet :id="$id" title="В продажу">
    <form method="post" action="/cars/{{ $vehicle->id }}/sell" class="flex flex-col gap-4" data-turbo-frame="_top" data-controller="sale-floor reveal" data-sale-floor-rules-value="@json(Pricing::RULES)" data-action="input->sale-floor#update change->sale-floor#update">
        @csrf
        <div class="text-sm text-ink-muted">{{ $vehicle->saleLabel() }}</div>
        <div class="fields">
            <x-ui.field digits name="value" label="Оценочная, ₽" :value="old('value', $value ? Money::nums($value) : '')" data-sale-floor-target="value" :id="$id.'-value'"/>
        </div>
        <div class="flex flex-col gap-1.5">
            <span class="field-label">Закупочная</span>
            <div class="list">
                <label class="row row-check">
                    <span class="min-w-0 flex-1">По правилу<span class="row-sub nums" data-sale-floor-target="rule"></span></span>
                    <span class="check"><input type="radio" name="floor_mode" value="rule" @checked($mode === 'rule') data-action="reveal#pick"></span>
                </label>
                <label class="row row-check">
                    <span class="min-w-0 flex-1">Равна оценочной<span class="row-sub nums" data-sale-floor-target="same"></span></span>
                    <span class="check"><input type="radio" name="floor_mode" value="value" @checked($mode === 'value') data-action="reveal#pick"></span>
                </label>
                <label class="row row-check">
                    <span class="min-w-0 flex-1">Своя</span>
                    <span class="check"><input type="radio" name="floor_mode" value="own" @checked($mode === 'own') data-action="reveal#pick"></span>
                </label>
            </div>
        </div>
        <div class="fields" data-reveal-target="pane" data-reveal-key="own" @if ($mode !== 'own') hidden @endif>
            <x-ui.field digits name="floor" label="Закупочная, ₽" :value="old('floor', $mode === 'own' && $floor ? Money::nums($floor) : '')" data-sale-floor-target="own" :id="$id.'-floor'"/>
        </div>
        <x-ui.sheet-foot data-sale-floor-target="button">Выставить в продажу</x-ui.sheet-foot>
    </form>
</x-ui.sheet>
