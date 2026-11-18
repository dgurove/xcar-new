{{-- Нижний таб-бар телефона: до четырёх разделов и «Кабинет». У раздела CRM — залитый значок (`x-ui.tab-icon`, 16.11.2026),
     у остальных вместо иконки — капсула с числом позиций (серая; у текущего лаймовая и одной ширины под три цифры),
     сверху справа лаймовый бейдж нового; у кабинета — аватар. Без позиций — точка.
     Бар один на всё приложение (data-turbo-permanent): Turbo не пересоздаёт его на переходе, числа новой страницы
     переносит tabbar_controller (только из бара свежее живого — `data-at`), текущий таб он берёт из `<body data-nav-tab>`.
     `data-match` — адреса раздела: по ним `lib/sections.js` узнаёт, какой раздел задело живое событие; `data-own` — раздел, чьи
     экраны всегда его («Чаты» сайта): чат, открытый из другого раздела, в нём не остаётся.
     На сайте значок — только у «Чатов» (18.11.2026, владелец), у остальных разделов — капсула с числом. --}}
@php
    $user = auth()->user();
    $tabs = \App\Support\Nav::tabs($user);
    $badges = \App\Support\Nav::badges($user);
    $totals = \App\Support\Nav::totals($user);
    // Таб экрана — одна дверь с `<body data-nav-tab>` (`Nav::currentTab`).
    $current = \App\Support\Nav::currentTab($user);
@endphp
<nav class="tabbar" id="tabbar" aria-label="Разделы" data-controller="tabbar" data-tabbar-shown-value="{{ $current }}" data-at="{{ (int) (microtime(true) * 1000) }}" data-turbo-permanent>
    @foreach ($tabs as $tab)
        @if ($tab['logout'] ?? false)
            <form method="post" action="/logout" class="contents">@csrf<button type="submit" class="tab"><span class="tab-mark"><span class="tab-dot"></span></span><span>{{ $tab['label'] }}</span></button></form>
            @continue
        @endif
        <a href="{{ $tab['href'] }}" class="tab" @if ($tab['go'] ?? null) data-go="{{ $tab['go'] }}" @endif data-match="{{ implode(' ', array_filter((array) $tab['match'], fn ($m) => ! str_starts_with($m, 'http'))) }}" @if ($tab['own'] ?? false) data-own @endif data-turbo-action="replace" data-action="tabbar#tap" @if ($tab['href'] === $current) aria-current="page" @endif>
            <span class="tab-mark">
                @if ($loop->last && $user)
                    <x-ui.avatar :user="$user" :size="26"/>
                @elseif ($tab['icon'] ?? null)
                    {{-- Раздел со значком (CRM, 16.11.2026): у текущего тот же значок зелёным, как в iOS; числа позиций нет. --}}
                    <x-ui.tab-icon :name="$tab['icon']"/>
                @elseif (isset($totals[$tab['href']]))
                    <span class="tab-count nums" data-total="{{ $tab['href'] }}">{{ \App\Support\Nav::short($totals[$tab['href']]) }}</span>
                @else
                    <span class="tab-dot"></span>
                @endif
                <x-ui.badge :href="$tab['href']" :badges="$badges"/>
            </span>
            <span>{{ $tab['label'] }}</span>
            <input type="checkbox" switch class="haptic" tabindex="-1" aria-hidden="true">
        </a>
    @endforeach
</nav>
