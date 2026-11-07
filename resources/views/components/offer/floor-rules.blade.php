{{-- Диапазоны наценки закупочной к оценочной (07.11.2026, владелец: «удобно регулировать диапазоны, менять значения и
     добавлять новые, для каждого предложения индивидуально»): строка — «до [оценочная] + [наценка] ₽ | %», последняя —
     «дальше» без границы. «+ Диапазон» делит последний, × убирает строку, «Как по умолчанию» — ступени вендора. В форму
     уходит одно скрытое поле `name` (JSON [[до, ₽, %], …], сервер приводит его `Pricing::clean`), закупочная считается на
     лету (`value-floor`, событие `floor-rules:change`). Одни на редактор партнёра, окошко и редактор CRM и шторку вендора. --}}
@props(['rules', 'default' => null, 'name' => 'floor_rules', 'form' => null, 'id' => 'floor-rules'])
@php
    use App\Support\Money;
    $default ??= $rules;
    $row = fn (?int $below, int $add, int|float $pct, bool $last) => ['below' => $below === null ? '' : Money::nums($below), 'amount' => $pct ? str_replace('.', ',', (string) $pct) : ($add ? Money::nums($add) : '0'), 'unit' => $pct ? 'pct' : 'rub', 'last' => $last];
    $rows = array_map(fn ($r, $i) => $row($r[0], $r[1], $r[2], $i === array_key_last($rules)), $rules, array_keys($rules));
@endphp
<div class="floor-rules" id="{{ $id }}" data-controller="floor-rules" data-floor-rules-default-value='@json($default)'>
    <input type="hidden" name="{{ $name }}" value='@json($rules)' data-floor-rules-target="input" @if ($form) form="{{ $form }}" @endif>
    <div class="floor-rules-list" data-floor-rules-target="list">
        @foreach ($rows as $r)
            @include('components.offer.floor-rule', $r)
        @endforeach
    </div>
    <template data-floor-rules-target="template">@include('components.offer.floor-rule', ['below' => '', 'amount' => '', 'unit' => 'rub', 'last' => false])</template>
    <p class="field-error" data-floor-rules-target="error" hidden></p>
    <div class="floor-rules-acts">
        <button type="button" class="btn btn-s btn-quiet" data-action="floor-rules#add"><x-ui.icon name="plus" class="size-4"/> Диапазон</button>
        <button type="button" class="btn btn-s btn-ghost" data-floor-rules-target="reset" data-action="floor-rules#reset" @if ($rules === $default) hidden @endif>Как по умолчанию</button>
    </div>
</div>
