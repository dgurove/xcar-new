{{-- Первый экран лендинга: фото парковки, что это, настоящее число (ноль не рисуется), два действия. --}}
<section class="hero hero-ground relative isolate overflow-hidden">
    <x-ui.photo-parking class="absolute inset-0 -z-10"/>
    <div class="hero-shade absolute inset-0 -z-10"></div>
    <div class="hero-content container-site flex h-full flex-col justify-end" style="padding-top: var(--spacing-header)">
        <div class="flex w-full max-w-2xl flex-col items-start">
            <h1 class="landing-title">{{ $title }}</h1>
            @if ($count > 0)
                <p class="nums hero-count mt-8 sm:mt-10">{{ $count }}</p>
                <p class="hero-sub mt-3 text-lg sm:text-xl">{{ $label }}</p>
            @endif
            <div class="mt-8 flex w-full flex-col gap-3 sm:mt-10 sm:w-auto sm:flex-row">
                <a href="{{ $primary[1] }}" class="btn btn-hero btn-accent">{{ $primary[0] }}</a>
                <a href="{{ $secondary[1] }}" class="btn btn-hero btn-quiet landing-quiet">{{ $secondary[0] }}</a>
            </div>
        </div>
    </div>
</section>
