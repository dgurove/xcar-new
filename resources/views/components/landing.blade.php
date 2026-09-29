{{-- Лендинг `/`: фото парковки стоит на месте, первый экран — что это и два действия, содержимое наезжает листом
     со скруглёнными углами. У шапки лист теряет скругление, и шапка берёт его цвет: одна поверхность (landing_controller). --}}
@props(['title', 'lead', 'primary', 'secondary'])
<div class="landing" data-controller="landing">
    <div class="landing-bg" aria-hidden="true">
        <x-ui.photo-parking class="absolute inset-0"/>
        <div class="landing-shade absolute inset-0"></div>
    </div>
    <section class="landing-hero container-site">
        <div class="landing-hero-body">
            <h1 class="landing-title">{{ $title }}</h1>
            <p class="landing-sub">{{ $lead }}</p>
            <div class="mt-8 flex w-full flex-col gap-3 sm:mt-10 sm:w-auto sm:flex-row">
                <a href="{{ $primary[1] }}" class="btn btn-hero btn-accent">{{ $primary[0] }}</a>
                <a href="{{ $secondary[1] }}" class="btn btn-hero landing-quiet">{{ $secondary[0] }}</a>
            </div>
        </div>
    </section>
    <div class="landing-sheet" data-landing-target="sheet">
        <div class="container-site flex flex-col gap-12 py-12 sm:gap-16 sm:py-16">{{ $slot }}</div>
    </div>
</div>
