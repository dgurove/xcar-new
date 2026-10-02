{{-- Доступ модератора: черновики предложений есть всегда; сверх них — галки CrmArea. Денег, сделок, закупок, людей и
     настроек у него нет. Одни поля в ссылке-приглашении и в карточке человека. --}}
@props(['areas' => []])
@php use App\Users\CrmArea; @endphp
<div class="flex flex-col gap-2">
    <span class="field-label">Сверх черновиков предложений</span>
    @foreach (CrmArea::cases() as $area)
        <x-ui.check name="crm_areas[]" :value="$area->value" :checked="in_array($area->value, old('crm_areas', $areas), true)">{{ $area->label() }}</x-ui.check>
    @endforeach
</div>
