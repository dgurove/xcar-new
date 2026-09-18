{{-- Кабинет: пилюли разделов (лента на телефоне, колонка от lg) и содержимое одной ширины.
     Заголовка-h1 и крошек нет: раздел называет текущая пилюля; в шапке телефона имя экрана
     появляется при прокрутке. Пилюли не липкие и не зависят от содержимого — стоят на месте
     на всех разделах. «Назад» — только у вложенных экранов (:back), рисуется в колонке содержимого. --}}
@props(['title', 'heading' => null, 'back' => null])
@php
    $user = auth()->user();
    $path = '/'.ltrim(request()->path(), '/');
    $groups = \App\Support\Nav::cabinet($user);
    $back = $back === false ? null : ($back ?? \App\Support\Nav::backFor($path, $user));
@endphp
{{-- Шеллу «назад» нужен для шапки телефона; свой ряд под него он не рисует — ссылка стоит в колонке содержимого. --}}
<x-ui.shell :title="$title" :heading="$heading ?? false" :back="$back ?? false" :back-row="false">
    <div class="grid gap-6 lg:grid-cols-[15rem_minmax(0,56rem)] lg:gap-8" data-title-anchor>
        {{-- min-w-0 обязателен: иначе лента шире экрана растягивает колонку сетки. --}}
        <nav class="min-w-0 lg:self-start" aria-label="Разделы кабинета">
            <div class="cabinet-pills -mx-4 flex snap-x snap-mandatory gap-1.5 overflow-x-auto px-4 pb-1 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden lg:mx-0 lg:flex-col lg:overflow-visible lg:px-0 lg:pb-0">
                @foreach ($groups as $group => $links)
                    @if ($group !== '' && count($groups) > 1)<span class="hidden pb-1 pl-5 pt-3 text-xs uppercase tracking-wide text-ink-dim first:pt-0 lg:block">{{ $group }}</span>@endif
                    @foreach ($links as $link)
                        <a href="{{ $link['href'] }}" class="pill pill-lg" data-turbo-action="replace" @if (\App\Support\Nav::isCurrentLink($link, $path)) aria-current="true" @endif @if (str_starts_with($link['href'], 'http')) data-turbo="false" @endif>{{ $link['label'] }}@if (str_starts_with($link['href'], 'http')) ↗@endif</a>
                    @endforeach
                @endforeach
            </div>
        </nav>
        <div class="flex min-w-0 flex-col gap-6">
            @if ($back)
                <a href="{{ $back[1] }}" class="hidden w-fit items-center gap-1 text-sm text-ink-muted transition-colors hover:text-ink sm:inline-flex" data-controller="back" data-action="back#go" data-turbo-action="replace"><x-ui.icon name="chevron-left" class="size-4"/>{{ $back[0] }}</a>
            @endif
            {{ $slot }}
        </div>
    </div>
</x-ui.shell>
