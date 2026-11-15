{{-- Окно «Кому показывать» лентой волн (15.11.2026, владелец: «невозможно одному менеджеру 5 дней, потом остальным», «сначала
     выбранным 5 дней, потом всем, кроме — 3 дня, потом всем до такого-то числа»; прежнее «Всем | Всем, кроме | Выбранным» на всё
     окно — с 12.11.2026). Волны рисует `audience_controller` (срок пилюлей, «Всем» с «кроме» или выбранные чипами, «Кому?» тремя
     кнопками у новой, «Добавить волну»), список групп и менеджеров с поиском — здесь, его ставят под волну. Конец ленты — слот
     `end` (конец приёма или публикации). Одно на предложение, публикацию сделки, «Вернуть в продажу», переоценку и вендора;
     deal — публикация машины в сделке: супер-покупателей в выборе нет. --}}
@props(['deal' => false, 'end' => null])
@php
    $groups = \App\Offers\AudienceRules::groups();
    $people = \App\Offers\AudienceRules::people();
    $count = $groups->count() + $people->count() + ($deal ? 0 : 1);
    // Имя для поиска — как запрос в `audience#filter`: строчными и без «ё».
    $key = fn ($name) => str_replace('ё', 'е', mb_strtolower($name));
@endphp
<div @class(["audience", "audience--bare" => ! $end])>
    <div class="waves" data-audience-target="body"></div>
    @if ($end)
        <div class="wave wave-end" data-audience-target="end"><span class="wave-dot"></span>{{ $end }}</div>
    @endif
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
                <label class="row row-check" data-audience-target="option" data-key="group:{{ $g->id }}" data-name="{{ $key($g->name) }}">
                    <x-ui.row-icon name="users" size="s"/>
                    <span class="min-w-0 flex-1"><span class="block truncate">{{ $g->name }}</span><span class="row-sub nums">группа, {{ $g->members_count }} {{ \App\Support\Plural::of($g->members_count, ['человек', 'человека', 'человек']) }}</span></span>
                    <span class="check"><input type="checkbox" data-action="audience#toggle"></span>
                </label>
            @endforeach
            @unless ($deal)
                <label class="row row-check" data-audience-target="option" data-key="super:0" data-name="{{ $key(\App\Offers\AudienceRules::SUPER_NAME) }}">
                    <x-ui.row-icon name="user" size="s"/>
                    <span class="min-w-0 flex-1 truncate">{{ \App\Offers\AudienceRules::SUPER_NAME }}</span>
                    <span class="check"><input type="checkbox" data-action="audience#toggle"></span>
                </label>
            @endunless
            @foreach ($people as $u)
                <label class="row row-check" data-audience-target="option" data-key="user:{{ $u->id }}" data-name="{{ $key($u->name) }}">
                    <x-ui.avatar :user="$u" :size="32"/>
                    <span class="min-w-0 flex-1 truncate">{{ $u->name }}</span>
                    <span class="check"><input type="checkbox" data-action="audience#toggle"></span>
                </label>
            @endforeach
        </div>
    </div>
    <template data-audience-target="icons"><x-ui.icon name="x" class="size-4" data-icon="x"/><x-ui.icon name="plus" class="size-4" data-icon="plus"/></template>
</div>
