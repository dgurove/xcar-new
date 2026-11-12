{{-- Окно «Кому показывать» (12.11.2026, владелец: показ менеджерам, волны и «Опубликовать для других» — одно и то же, а «всем,
     кроме» не было нигде): сверху «Всем | Всем, кроме | Выбранным», ниже — кого не показывать чипами, выбранные строками со
     временем, волны («+ Волна» — группа или менеджер в другое время, чем остальные). «Добавить» — список групп и менеджеров с
     поиском прямо здесь, можно отметить нескольких. Внутри — те же строки «кто → когда» (`AudienceRules`), строки рисует
     `audience_controller`, список выбора — здесь. Одно на предложение, публикацию сделки, «Вернуть в продажу», переоценку и
     вендора; deal — публикация машины в сделке: супер-покупателей в выборе нет. --}}
@props(['deal' => false])
@php
    $groups = \App\Offers\AudienceRules::groups();
    $people = \App\Offers\AudienceRules::people();
    $mode = 'aud-'.\Illuminate\Support\Str::random(6);
    $count = $groups->count() + $people->count() + ($deal ? 0 : 1);
@endphp
<div class="audience">
    <div class="segment" role="radiogroup" aria-label="Кому показывать">
        @foreach (['all' => 'Всем', 'except' => 'Всем, кроме', 'only' => 'Выбранным'] as $value => $label)
            <label><input type="radio" name="_{{ $mode }}" value="{{ $value }}" data-audience-target="mode" data-action="audience#mode">{{ $label }}</label>
        @endforeach
    </div>
    <div class="audience-body" data-audience-target="body"></div>
    <div class="audience-pick" data-audience-target="pick" hidden>
        <div class="audience-pick-head">
            @if ($count > 8)
                <input type="search" class="field-input field-s min-w-0 flex-1" placeholder="Найти" autocomplete="off" data-audience-target="search" data-action="input->audience#filter keydown.enter->audience#enter" aria-label="Найти менеджера или группу">
            @else
                <span class="min-w-0 flex-1 text-sm text-ink-muted" data-audience-target="pickTitle"></span>
            @endif
            <button type="button" class="btn btn-s btn-quiet shrink-0" data-action="audience#close">Готово</button>
        </div>
        <div class="list">
            @foreach ($groups as $g)
                <label class="row row-check" data-audience-target="option" data-key="group:{{ $g->id }}" data-name="{{ mb_strtolower($g->name) }}">
                    <x-ui.row-icon name="users" size="s"/>
                    <span class="min-w-0 flex-1"><span class="block truncate">{{ $g->name }}</span><span class="row-sub nums">группа, {{ $g->members_count }} {{ \App\Support\Plural::of($g->members_count, ['человек', 'человека', 'человек']) }}</span></span>
                    <span class="check"><input type="checkbox" data-action="audience#toggle"></span>
                </label>
            @endforeach
            @unless ($deal)
                <label class="row row-check" data-audience-target="option" data-key="super:0" data-name="{{ mb_strtolower(\App\Offers\AudienceRules::SUPER_NAME) }}">
                    <x-ui.row-icon name="user" size="s"/>
                    <span class="min-w-0 flex-1 truncate">{{ \App\Offers\AudienceRules::SUPER_NAME }}</span>
                    <span class="check"><input type="checkbox" data-action="audience#toggle"></span>
                </label>
            @endunless
            @foreach ($people as $u)
                <label class="row row-check" data-audience-target="option" data-key="user:{{ $u->id }}" data-name="{{ mb_strtolower($u->name) }}">
                    <x-ui.avatar :user="$u" :size="32"/>
                    <span class="min-w-0 flex-1 truncate">{{ $u->name }}</span>
                    <span class="check"><input type="checkbox" data-action="audience#toggle"></span>
                </label>
            @endforeach
        </div>
    </div>
    <template data-audience-target="icons"><x-ui.icon name="x" class="size-4" data-icon="x"/><x-ui.icon name="plus" class="size-4" data-icon="plus"/></template>
</div>
