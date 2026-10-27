{{-- Лендинг `/`: фото парковки стоит на месте, на первом экране — только «Войти» (27.10.2026, владелец: «надписи — кринж,
     нам не нужна реклама, у нас закрытая система»); title и lead — по желанию. Содержимое (слот) наезжает листом со
     скруглёнными углами; пустой слот — листа нет. У шапки лист теряет скругление (landing_controller). --}}
@props(['title' => null, 'lead' => null, 'primary', 'secondary' => null])
<div class="landing" data-controller="landing">
    <div class="landing-bg" aria-hidden="true">
        <x-ui.photo-parking class="absolute inset-0"/>
        <div class="landing-shade absolute inset-0"></div>
    </div>
    <section class="landing-hero container-site">
        <div class="landing-hero-body">
            @if ($title)<h1 class="landing-title">{{ $title }}</h1>@endif
            @if ($lead)<p class="landing-sub">{{ $lead }}</p>@endif
            <div class="mt-8 flex w-full flex-col gap-3 sm:mt-10 sm:w-auto sm:flex-row">
                <a href="{{ $primary[1] }}" class="btn btn-hero btn-accent">{{ $primary[0] }}</a>
                @if ($secondary)<a href="{{ $secondary[1] }}" class="btn btn-hero landing-quiet">{{ $secondary[0] }}</a>@endif
            </div>
        </div>
    </section>
    @if (trim($slot) !== '')
    <div class="landing-sheet" data-landing-target="sheet">
        <div class="container-site flex flex-col gap-12 py-12 sm:gap-16 sm:py-16">{{ $slot }}</div>
    </div>
    @endif
</div>
