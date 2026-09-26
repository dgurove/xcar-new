{{-- Нижний таб-бар телефона: до четырёх разделов и «Кабинет». У раздела вместо иконки —
     капсула с числом позиций (серая; у текущего лаймовая и одной ширины под три цифры),
     сверху справа лаймовый бейдж нового; у кабинета — аватар. Без позиций — точка. --}}
@php
    $user = auth()->user();
    $path = '/'.ltrim(request()->path(), '/');
    $tabs = \App\Support\Nav::tabs($user);
    $badges = \App\Support\Nav::badges($user);
    $totals = \App\Support\Nav::totals($user);
    // Активен самый точный пункт: «Сделки» в /account/deals, а не «Кабинет».
    $current = collect($tabs)->filter(fn ($t) => \App\Support\Nav::isCurrent($t, $path))->sortByDesc(fn ($t) => \App\Support\Nav::matchLength($t, $path))->first()['href'] ?? null;
@endphp
<nav class="tabbar" id="tabbar" aria-label="Разделы" data-controller="tabbar" data-tabbar-target="bar" data-tabbar-shown-value="{{ $current }}">
    @foreach ($tabs as $tab)
        @if ($tab['logout'] ?? false)
            <form method="post" action="/logout" class="contents">@csrf<button type="submit" class="tab"><span class="tab-mark"><span class="tab-dot"></span></span><span>{{ $tab['label'] }}</span></button></form>
            @continue
        @endif
        <a href="{{ $tab['href'] }}" class="tab" data-turbo-action="replace" data-action="tabbar#tap" @if ($tab['href'] === $current) aria-current="page" @endif>
            <span class="tab-mark">
                @if ($loop->last && $user)
                    <x-ui.avatar :user="$user" :size="22"/>
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
