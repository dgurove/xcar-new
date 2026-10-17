{{-- Поля срока этапа (экран «Таймеры»): сколько и в чём (мин, ч, дн, раб. дн), пусто — без срока; галка «и N сделкам на этапе». --}}
@php
    $m = $stage?->limit_minutes;
    $unit = $m === null ? 'days' : ($stage->limit_workdays ? 'workdays' : ($m % 1440 === 0 ? 'days' : ($m % 60 === 0 ? 'hours' : 'minutes')));
    $value = $m === null ? null : match ($unit) { 'workdays', 'days' => intdiv($m, 1440), 'hours' => intdiv($m, 60), default => $m };
@endphp
<div class="fields">
    <x-ui.field name="limit_value" label="Сколько ждём" :value="old('limit_value', $value)" inputmode="numeric" placeholder="без срока"/>
</div>
<x-ui.segment name="limit_unit" :options="['minutes' => 'мин', 'hours' => 'ч', 'days' => 'дн', 'workdays' => 'раб. дн']" :value="old('limit_unit', $unit)"/>
@if ($occupied)
    <input type="hidden" name="retime" value="0">
    <x-ui.check name="retime" :checked="true">И {{ $occupied }} {{ \App\Support\Plural::of($occupied, ['сделке', 'сделкам', 'сделкам']) }} на этапе</x-ui.check>
@endif
