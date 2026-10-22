{{-- Заголовок экрана — название текущей вкладки, по нажатию все вкладки списком (22.10.2026, владелец: пилюль у
     «Предложений» стало восемь, «если вкладок много, их логичнее вертикально размещать»). Те же ключи и ссылки, что у
     пилюль x-ui.toolbar: pills — ключ → подпись, counts — числа, badges — лаймовый бейдж (ключ числа в counts), bumps —
     своё число для вспышки (у «Из таблицы Кати»). Числа в меню держат `data-pill-count`, как пилюли: их тикают
     live.js и flip.js, вспыхивает само название (lib/bump.js). Бейдж в другой вкладке — точка после названия (CSS).
     У названия ни числа, ни стрелки (владелец 22.10.2026: «криво, смещает всё»), текущая в списке — лаймовой строкой.
     Вкладки нет в адресе (поиск) — fallback. --}}
@props(['pills' => [], 'pill' => '', 'pillParam' => 'view', 'pillHome' => null, 'counts' => [], 'badges' => [], 'bumps' => [], 'fallback' => '', 'id' => 'tab-menu'])
@php
    $action = '/'.ltrim(request()->path(), '/');
    $query = request()->query();
    $url = fn (array $set) => $action.'?'.str_replace('%2C', ',', http_build_query(array_filter(array_merge($query, $set), fn ($v) => $v !== null && $v !== '')));
@endphp
<div {{ $attributes->merge(['class' => 'tab-title']) }} data-controller="menu">
    <h1 class="text-xl sm:text-2xl">
        <button type="button" class="tab-title-btn" data-bump data-action="menu#toggle" aria-haspopup="menu" aria-controls="{{ $id }}">
            {{ $pills[$pill] ?? $fallback }}
        </button>
    </h1>
    <div id="{{ $id }}" class="menu tab-title-menu" popover data-menu-target="list" role="menu">
        @foreach ($pills as $key => $label)
            <a href="{{ $url([$pillParam => $key === '' || $key === ($pillHome ?? array_key_first($pills)) ? null : $key, 'page' => null]) }}" class="menu-item" role="menuitem" data-action="menu#close" data-turbo-action="replace" @isset($bumps[$key]) data-pill-bump="{{ $key }}:{{ $counts[$bumps[$key]] ?? 0 }}" @endisset @if ((string) $pill === (string) $key) aria-current="true" @endif>
                <span class="min-w-0 flex-1">{{ $label }}</span>
                @isset($badges[$key])<span class="badge nums" data-pill-count="{{ $badges[$key] }}">{{ ($counts[$badges[$key]] ?? 0) ?: '' }}</span>@endisset
                @if (array_key_exists($key, $counts))<span class="nums text-ink-dim" data-pill-count="{{ $key }}">{{ $counts[$key] ?: '' }}</span>@endif
            </a>
        @endforeach
    </div>
</div>
