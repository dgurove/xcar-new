{{-- Первый экран: фото паркинга, крупное число, одна кнопка. Каталог наезжает поверх. --}}
@props(['count' => 0, 'label' => null, 'href' => '/', 'button' => 'Смотреть предложения', 'cue' => '#catalog-section'])
<section class="hero hero-ground relative isolate overflow-hidden">
    <x-ui.photo-parking class="absolute inset-0 -z-10"/>
    <div class="hero-shade absolute inset-0 -z-10"></div>
    <div class="hero-content container-site flex h-full flex-col justify-end" style="padding-top: var(--spacing-header)">
        <h1 class="sr-only">{{ config('app.name') }}</h1>
        <div class="flex w-full max-w-2xl flex-col items-start">
            @if ($count > 0)
                <p class="nums hero-count">{{ $count }}</p>
                <p class="hero-sub mt-4 text-[17px] sm:mt-5 sm:text-xl">{{ $label }}</p>
            @endif
            <a href="{{ $href }}" class="btn btn-hero btn-accent mt-7 w-full sm:mt-9 sm:w-auto">{{ $button }}</a>
        </div>
    </div>
    @if ($cue)
        <a href="{{ $cue }}" aria-label="К предложениям" class="hero-cue absolute inset-x-0 bottom-4 mx-auto hidden size-10 items-center justify-center md:flex">
            <x-ui.icon name="arrow-down" class="size-[22px]" stroke-width="1.6"/>
        </a>
    @endif
</section>
