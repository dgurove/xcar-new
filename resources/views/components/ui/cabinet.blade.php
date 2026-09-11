{{-- Кабинет: пилюли разделов (лента на телефоне, колонка от lg) и содержимое. --}}
@props(['title', 'heading' => null, 'trail' => [], 'surface' => null])
@php
    $surface ??= \App\Http\Middleware\ParkHost::isPark(request()) ? 'park' : 'site';
    $user = auth()->user();
    $path = '/'.ltrim(request()->path(), '/');
    $groups = \App\Support\Nav::cabinet($user, $surface);
@endphp
<x-ui.shell :title="$title" :heading="$heading ?? $title" :trail="$trail" :surface="$surface">
    <div class="grid gap-6 lg:grid-cols-[15rem_1fr]">
        {{-- min-w-0 обязателен: иначе лента шире экрана растягивает колонку сетки. --}}
        <nav class="min-w-0 lg:sticky lg:top-32 lg:self-start" aria-label="Разделы кабинета">
            <div class="-mx-4 flex snap-x snap-mandatory gap-1.5 overflow-x-auto px-4 pb-1 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden lg:mx-0 lg:flex-col lg:overflow-visible lg:px-0 lg:pb-0">
                @foreach ($groups as $group => $links)
                    @if ($group !== '' && count($groups) > 1)<span class="hidden pb-1 pl-5 pt-3 text-xs uppercase tracking-wide text-ink-dim first:pt-0 lg:block">{{ $group }}</span>@endif
                    @foreach ($links as $link)
                        <a href="{{ $link['href'] }}" class="pill pill-lg" @if (\App\Support\Nav::isCurrentLink($link, $path)) aria-current="true" @endif @if (str_starts_with($link['href'], 'http')) data-turbo="false" @endif>{{ $link['label'] }}</a>
                    @endforeach
                @endforeach
            </div>
        </nav>
        <div class="min-w-0">{{ $slot }}</div>
    </div>
</x-ui.shell>
