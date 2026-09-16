{{-- Нижний таб-бар телефона: до четырёх разделов и «Кабинет» с аватаром. Иконок нет — у раздела
     точка, у текущего она вытягивается в лаймовую черту (.tab-mark): картинка не подсказывает лишнего. --}}
@php
    $user = auth()->user();
    $path = '/'.ltrim(request()->path(), '/');
    $tabs = \App\Support\Nav::tabs($user);
    $badges = \App\Support\Nav::badges($user);
    // Активен самый точный пункт: «Сделки» в /lk/sdelki, а не «Кабинет».
    $current = collect($tabs)->filter(fn ($t) => \App\Support\Nav::isCurrent($t, $path))->sortByDesc(fn ($t) => \App\Support\Nav::matchLength($t, $path))->first()['href'] ?? null;
@endphp
<nav class="tabbar" id="tabbar" aria-label="Разделы" data-controller="tabbar" data-tabbar-target="bar">
    @foreach ($tabs as $tab)
        @if ($tab['logout'] ?? false)
            <form method="post" action="/vyhod" class="contents">@csrf<button type="submit" class="tab"><span class="tab-mark"></span><span>{{ $tab['label'] }}</span></button></form>
            @continue
        @endif
        <a href="{{ $tab['href'] }}" class="tab" data-turbo-action="replace" data-action="tabbar#tap" @if ($tab['href'] === $current) aria-current="page" @endif>
            @if ($loop->last && $user)
                <x-ui.avatar :user="$user" :size="25"/>
            @else
                <span class="tab-mark"></span>
            @endif
            <span>{{ $tab['label'] }}</span>
            <x-ui.badge :href="$tab['href']" :badges="$badges"/>
            <input type="checkbox" switch class="haptic" tabindex="-1" aria-hidden="true">
        </a>
    @endforeach
</nav>
