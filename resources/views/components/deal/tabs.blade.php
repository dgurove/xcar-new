{{-- Пилюли раздела «Сделки»: сделки, покупатели (у менеджера) и чаты — один таб. На телефоне стоят вместо h1
     (шелл с :phone-heading="false"), как пилюли каталога; число — сколько всего, лаймовый бейдж — что ждёт его. На телефоне
     серого числа нет: с ним три пилюли не влезали, ряд листался и прыгал от экрана к экрану (11.10.2026). --}}
@props(['current'])
@php
    $user = auth()->user();
    $totals = \App\Support\Nav::totals($user);
    $badges = \App\Support\Nav::badges($user);
    // Чаты — и тут (11.10.2026, владелец: «в кабинете чаты обычно не ищут»): тот же экран, лаймовый бейдж — непрочитанное.
    $pills = array_filter([
        '/deals' => ['Сделки', '/deals/asks'],
        '/buyers' => $user->isManager() ? ['Покупатели', '/buyers'] : null,
        '/deals/chats' => $user->canChat() ? ['Чаты', '/account/chats'] : null,
    ]);
@endphp
<div class="toolbar-pills -mx-4 snap-x overflow-x-auto px-4 [scrollbar-width:none] md:mx-0 md:px-0 [&::-webkit-scrollbar]:hidden" data-title-anchor>
    <div class="flex flex-nowrap items-center gap-2">
        @foreach ($pills as $href => [$label, $badge])
            <a href="{{ $href }}" class="pill" data-turbo-action="replace" @if ($current === $href) aria-current="true" @endif>
                {{ $label }}@if (!empty($totals[$href])) <span class="nums opacity-70 max-sm:hidden">{{ $totals[$href] }}</span>@endif
                <x-ui.badge :href="$badge" :badges="$badges"/>
            </a>
        @endforeach
    </div>
</div>
