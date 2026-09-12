{{-- Подвал: ряд капсул и знак. --}}
@php $surface = \App\Support\Surface::current(); @endphp
<footer class="footer relative z-10 bg-bar pt-12 text-ink">
    <div class="container-site">
        <div class="header-row">
            @if ($surface !== \App\Support\Surface::Site)
                <a href="{{ \App\Support\Surface::Site->url() }}" class="header-btn header-h min-w-0 flex-auto px-4 text-sm sm:px-5" data-turbo="false"><span class="truncate">На сайт xcar.ru</span></a>
            @else
                <a href="/obrabotka-dannyh" class="header-btn header-h min-w-0 flex-auto px-4 text-sm sm:px-5"><span class="truncate">Обработка данных</span></a>
                <a href="/soglashenie" class="header-btn header-h min-w-0 flex-auto px-4 text-sm sm:px-5"><span class="truncate">Соглашение</span></a>
            @endif
            <a href="/" class="header-btn-square flex h-[1.875rem] w-[1.875rem] shrink-0 items-center justify-center sm:w-auto" aria-label="XCar">
                <x-ui.logo responsive class="h-[1.875rem] w-auto"/>
            </a>
        </div>
        <p class="nums mt-6 text-sm font-normal text-ink-dim">© XCAR {{ date('Y') }}</p>
    </div>
</footer>
