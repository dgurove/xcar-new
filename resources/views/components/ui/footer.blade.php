{{-- Подвал: капсулы ссылок (О компании, документы) и знак. --}}
@php $surface = \App\Support\Surface::current(); @endphp
<footer class="footer relative z-10 bg-bar pt-12 text-ink">
    <div class="container-site">
        {{-- Ссылки переносятся строкой ниже, а не режутся многоточием: на телефоне трём капсулам тесно. --}}
        <div class="flex items-start gap-2">
            <div class="flex min-w-0 flex-1 flex-wrap gap-2">
                @if ($surface !== \App\Support\Surface::Site)
                    <a href="{{ \App\Support\Surface::Site->url() }}" class="header-btn header-h px-4 text-sm sm:px-5" data-turbo="false">На сайт xcar.ru</a>
                @else
                    <a href="/company" class="header-btn header-h px-4 text-sm sm:px-5">О компании</a>
                    <a href="/privacy" class="header-btn header-h px-4 text-sm sm:px-5">Обработка данных</a>
                    <a href="/terms" class="header-btn header-h px-4 text-sm sm:px-5">Соглашение</a>
                @endif
            </div>
            <a href="/" class="header-btn-square flex h-[1.875rem] w-[1.875rem] shrink-0 items-center justify-center sm:w-auto" aria-label="XCar">
                <x-ui.logo responsive class="h-[1.875rem] w-auto"/>
            </a>
        </div>
        <p class="nums mt-6 text-sm font-normal text-ink-dim">© XCAR {{ date('Y') }}</p>
    </div>
</footer>
