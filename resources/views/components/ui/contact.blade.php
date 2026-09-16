{{-- Шапка-контакт: кружок слева, имя и чипы фактов рядом, ряд круглых действий с подписью
     (слот acts) справа — одной компактной строкой и на телефоне, и на десктопе; sidebar — в
     правой колонке от lg столбиком в карточке. user — аватар, без него — кружок с иконкой (группа). --}}
@props(['name', 'user' => null, 'icon' => 'users', 'sidebar' => false])
@php $col = $sidebar ? ' lg:flex-col lg:items-center lg:text-center lg:rounded-(--radius-xl) lg:bg-surface lg:p-6' : ''; $center = $sidebar ? ' lg:justify-center' : ''; @endphp
<div {{ $attributes->merge(['class' => 'flex flex-wrap items-center gap-x-4 gap-y-3'.$col]) }}>
    @if ($user)
        <x-ui.avatar :user="$user" :size="56" class="text-xl sm:!size-16 sm:!text-2xl"/>
    @else
        <span class="flex size-14 shrink-0 items-center justify-center rounded-full bg-surface-3 text-ink sm:size-16"><x-ui.icon :name="$icon" class="size-7"/></span>
    @endif
    <div class="min-w-0 flex-1">
        <h1 class="truncate text-[22px] leading-tight sm:text-[28px]">{{ $name }}</h1>
        @if (isset($chips) && !$chips->isEmpty())<div class="mt-1.5 flex flex-wrap gap-1.5{{ $center }}">{{ $chips }}</div>@endif
    </div>
    @if (isset($acts) && !$acts->isEmpty())<div class="acts shrink-0{{ $center }}">{{ $acts }}</div>@endif
</div>
