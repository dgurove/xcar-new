{{-- Шапка-контакт, как карточка в телефоне: крупный кружок, имя, чипы фактов, ряд круглых
     действий с подписью (слот acts). На телефоне по центру, от sm — в строку; sidebar — в
     правой колонке от lg снова столбиком в карточке. user — аватар, без него — кружок с иконкой (группа). --}}
@props(['name', 'user' => null, 'icon' => 'users', 'sidebar' => false])
@php $col = $sidebar ? ' lg:flex-col lg:items-center lg:text-center lg:rounded-(--radius-xl) lg:bg-surface lg:p-6' : ''; $center = $sidebar ? ' lg:justify-center' : ''; @endphp
<div {{ $attributes->merge(['class' => 'flex flex-col items-center gap-4 text-center sm:flex-row sm:items-start sm:text-left'.$col]) }}>
    @if ($user)
        <x-ui.avatar :user="$user" :size="88" class="text-3xl sm:!size-16 sm:!text-2xl"/>
    @else
        <span class="flex size-[88px] shrink-0 items-center justify-center rounded-full bg-surface-3 text-ink sm:size-16"><x-ui.icon :name="$icon" class="size-10 sm:size-7"/></span>
    @endif
    <div class="min-w-0 flex-1">
        <h1 class="text-[28px] leading-tight sm:text-[34px]">{{ $name }}</h1>
        @if (isset($chips) && !$chips->isEmpty())<div class="mt-2 flex flex-wrap justify-center gap-1.5 sm:justify-start{{ $center }}">{{ $chips }}</div>@endif
    </div>
    @if (isset($acts) && !$acts->isEmpty())<div class="acts shrink-0 sm:pt-1{{ $center }}">{{ $acts }}</div>@endif
</div>
