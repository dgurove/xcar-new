{{-- Фильтр «Ответственный» (05.11.2026, владелец: «очень важно — вместо кнопки к центру, всегда видна»): плашка в правом нижнем
     углу сцены — кто выбран (аватар, имя, «⌄» — меню выбора) и тумблер. Включён — на доске только подэтапы выбранного и его
     числа (`space/filter.js`). По умолчанию — сам вошедший; выбор помнит браузер. `data-space-ui` — жесты сцены мимо неё. --}}
@php
    $me = auth()->user();
    $mine = collect($people)->firstWhere('key', 'user:'.$me->id);
@endphp
<div class="space-filter" data-space-ui data-space-target="filter" data-controller="menu">
    <button type="button" class="space-filter-who" data-action="menu#toggle" aria-haspopup="menu" aria-controls="space-people" aria-label="Чьи подэтапы показать">
        <span class="contents" data-space-target="who">
            @include('admin.space.filter-face', ['p' => $mine ?? ['key' => 'user:'.$me->id, 'name' => $me->name, 'user' => $me, 'icon' => null]])
        </span>
        <x-ui.icon name="chevron-down" class="size-4 opacity-60"/>
    </button>
    <input type="checkbox" class="switch" data-space-target="toggle" data-action="space#toggle" aria-label="Только подэтапы выбранного">
    <div id="space-people" class="menu space-people" popover data-menu-target="list" role="menu">
        @foreach ($people as $p)
            <button type="button" class="menu-item" role="menuitemradio" data-key="{{ $p['key'] }}" data-action="space#choose menu#close" @if ($p['key'] === 'user:'.$me->id) aria-current="true" @endif>
                @include('admin.space.filter-face', ['p' => $p])
            </button>
        @endforeach
    </div>
</div>
