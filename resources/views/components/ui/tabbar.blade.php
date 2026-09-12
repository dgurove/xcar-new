{{-- Нижний таб-бар телефона: до четырёх разделов и «Кабинет» с аватаром. --}}
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
            <form method="post" action="/vyhod" class="contents">@csrf<button type="submit" class="tab"><x-ui.icon name="exit" class="icon-line"/><x-ui.icon name="exit" fill class="icon-fill"/><span>{{ $tab['label'] }}</span></button></form>
            @continue
        @endif
        <a href="{{ $tab['href'] }}" class="tab" data-turbo-action="replace" data-action="tabbar#tap" @if ($tab['href'] === $current) aria-current="page" @endif>
            @if ($loop->last && $user)
                <x-ui.avatar :user="$user" :size="25"/>
            @else
                <x-ui.icon :name="$tab['icon']" class="icon-line"/><x-ui.icon :name="$tab['icon']" fill class="icon-fill"/>
            @endif
            <span>{{ $tab['label'] }}</span>
            <x-ui.badge :href="$tab['href']" :badges="$badges"/>
        </a>
    @endforeach
</nav>
