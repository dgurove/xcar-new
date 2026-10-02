{{-- Доступ модератора: предложения его групп есть всегда (модераторы одной группы видят и правят предложения друг
     друга, группы — в «Пользователях → Сотрудники»); сверх них — галки CrmArea. Денег, сделок, закупок, людей и настроек
     у него нет. В карточке человека группы чипами, в ссылке-приглашении — одна, сразу в неё. --}}
@props(['areas' => [], 'user' => null])
@php
    use App\Users\{CrmArea, UserGroup};
    $groups = UserGroup::ofKind(UserGroup::MODERATORS)->get();
@endphp
<x-admin.group-picker :groups="$groups" :user="$user" name="moderator"/>
<div class="flex flex-col gap-2">
    <span class="field-label">Сверх предложений группы</span>
    @foreach (CrmArea::cases() as $area)
        <x-ui.check name="crm_areas[]" :value="$area->value" :checked="in_array($area->value, old('crm_areas', $areas), true)">{{ $area->label() }}</x-ui.check>
    @endforeach
</div>
