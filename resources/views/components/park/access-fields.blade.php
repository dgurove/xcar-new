{{-- Доступ управляющего парковкой: заявки, наличие и дело ТС есть всегда; сверх них — галки разделов; своя парковка
     (пусто — все) и «только приёмка» (без отмен, удалений, правки реквизитов ТС и выдачи по QR). Настроек — вендоров,
     прайса, парковок, реквизитов — у него нет. Одни поля в ссылке-приглашении и в карточке человека. --}}
@props(['areas' => [], 'yard' => null, 'readonly' => false])
@php use App\Park\Area; @endphp
<div class="flex flex-col gap-2">
    <span class="field-label">Сверх заявок и наличия</span>
    @foreach (Area::cases() as $area)
        <x-ui.check name="areas[]" :value="$area->value" :checked="in_array($area->value, old('areas', $areas), true)">{{ $area->label() }}</x-ui.check>
    @endforeach
</div>
<x-ui.field name="park_yard_id" label="Парковка" :options="\App\Park\Yard::where('is_active', true)->orderBy('name')->pluck('name', 'id')" placeholder="Все" :value="old('park_yard_id', $yard)"/>
<x-ui.check name="park_readonly" :checked="(bool) old('park_readonly', $readonly)">Только приёмка</x-ui.check>
