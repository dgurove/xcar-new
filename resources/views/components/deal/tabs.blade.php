{{-- Пилюли раздела «Сделки» у менеджера: сделки и покупатели — один таб, два экрана. На телефоне стоят вместо h1
     (шелл с :phone-heading="false"), как пилюли каталога; число — сколько всего, лаймовый бейдж — что ждёт его. --}}
@props(['current'])
@php
    $user = auth()->user();
    $totals = \App\Support\Nav::totals($user);
    $badges = \App\Support\Nav::badges($user);
    $pills = ['/deals' => ['Сделки', '/deals/asks'], '/buyers' => ['Покупатели', '/buyers']];
@endphp
<div class="toolbar-pills -mx-4 snap-x overflow-x-auto px-4 [scrollbar-width:none] md:mx-0 md:px-0 [&::-webkit-scrollbar]:hidden" data-title-anchor>
    <div class="flex flex-nowrap items-center gap-2">
        @foreach ($pills as $href => [$label, $badge])
            <a href="{{ $href }}" class="pill" data-turbo-action="replace" @if ($current === $href) aria-current="true" @endif>
                {{ $label }}@if (!empty($totals[$href])) <span class="nums opacity-70">{{ $totals[$href] }}</span>@endif
                <x-ui.badge :href="$badge" :badges="$badges"/>
            </a>
        @endforeach
    </div>
</div>
