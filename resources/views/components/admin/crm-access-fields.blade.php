{{-- Доступ модератора: предложения его группы есть всегда — «Вместе с» (модераторы одной группы видят и правят
     предложения друг друга, User::crmTeamWith); сверх них — галки CrmArea. Денег, сделок, закупок, людей и настроек у него
     нет. Одни поля в ссылке-приглашении и в карточке человека. --}}
@props(['areas' => [], 'user' => null])
@php
    use App\Users\{CrmArea, Role, User};
    $others = User::where('role', Role::Moderator)->when($user, fn ($q) => $q->whereKeyNot($user->id))->orderBy('name')->pluck('name', 'id');
    $with = $user?->teammates()->first()?->id;
@endphp
@if ($others->isNotEmpty())
    <x-ui.field name="crm_team_with" label="Вместе с" :options="$others" placeholder="Один" :value="old('crm_team_with', $with)"/>
@endif
<div class="flex flex-col gap-2">
    <span class="field-label">Сверх предложений группы</span>
    @foreach (CrmArea::cases() as $area)
        <x-ui.check name="crm_areas[]" :value="$area->value" :checked="in_array($area->value, old('crm_areas', $areas), true)">{{ $area->label() }}</x-ui.check>
    @endforeach
</div>
