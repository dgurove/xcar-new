{{-- Кабинет. ПК: колонка пилюль (лента на md–lg) — только то, чего нет в шапке (Nav::cabinetFor 'desktop'),
     и только на экранах этих пилюль; раздел шапки (Вендоры, Сделки) — полноширинный экран с h1, как шелл.
     Телефон: пилюль нет — разделы кабинета меню строками на его корне (cabinet/profile), экран раздела со своим h1
     и «Назад» в шапке (Nav::phoneBack). «Назад» вложенных экранов (:back) на ПК — ссылкой в колонке содержимого. --}}
@props(['title', 'heading' => null, 'back' => null, 'root' => false])
@php
    use App\Support\Nav;
    $user = auth()->user();
    $path = '/'.ltrim(request()->path(), '/');
    $groups = Nav::cabinetFor($user, 'desktop');
    $pills = Nav::inCabinet($path, $user, 'desktop');
    $back = $back === false ? null : ($back ?? Nav::backFor($path, $user));
    $phoneBack = $back ?? Nav::phoneBack($path, $user);
    $heading ??= $title;
@endphp
{{-- Шелл рисует «назад» в шапке телефона. h1 — на телефоне всегда, кроме корня (там карточка профиля); на ПК — только
     без пилюль: с ними раздел называет текущая пилюля. --}}
<x-ui.shell :title="$title" :heading="$root && $pills ? false : $heading" :back="$back ?? false" :phone-back="$phoneBack" :back-row="false" :phone-heading="! $root" :desktop-heading="! $pills">
    <div @class(['grid gap-6', 'lg:grid-cols-[15rem_minmax(0,56rem)] lg:gap-8' => $pills]) data-title-anchor>
        @if ($pills)
            {{-- min-w-0 обязателен: иначе лента шире экрана растягивает колонку сетки. --}}
            <nav class="min-w-0 max-md:hidden lg:self-start" aria-label="Разделы кабинета">
                <div class="cabinet-pills -mx-4 flex snap-x snap-mandatory gap-1.5 overflow-x-auto px-4 pb-1 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden lg:mx-0 lg:flex-col lg:overflow-visible lg:px-0 lg:pb-0">
                    @foreach ($groups as $group => $links)
                        @if ($group !== '' && count($groups) > 1)<span class="hidden pb-1 pl-5 pt-3 text-xs uppercase tracking-wide text-ink-dim first:pt-0 lg:block">{{ $group }}</span>@endif
                        @foreach ($links as $link)
                            <a href="{{ $link['href'] }}" class="pill pill-lg" data-turbo-action="replace" @if (Nav::isCurrentLink($link, $path)) aria-current="true" @endif @if (str_starts_with($link['href'], 'http')) data-turbo="false" @endif>{{ $link['label'] }}@if (str_starts_with($link['href'], 'http')) ↗@endif</a>
                        @endforeach
                    @endforeach
                </div>
            </nav>
        @endif
        <div class="flex min-w-0 flex-col gap-6">
            @if ($back)
                <a href="{{ $back[1] }}" class="hidden w-fit items-center gap-1 text-sm text-ink-muted transition-colors hover:text-ink md:inline-flex" data-controller="back" data-action="back#go" data-turbo-action="replace"><x-ui.icon name="chevron-left" class="size-4"/>{{ $back[0] }}</a>
            @endif
            {{ $slot }}
        </div>
    </div>
</x-ui.shell>
