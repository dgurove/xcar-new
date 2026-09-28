{{-- Подвал: ряд капсул как в шапке (О компании, документы) и знак. --}}
@php $surface = \App\Support\Surface::current(); @endphp
<footer class="footer relative z-10 bg-bar pt-12 text-ink">
    <div class="container-site">
        {{-- Ряд шапки: капсулы делят его поровну до знака. На сайте на телефоне трём капсулам тесно —
             два ряда, как у шапки на ПК: вторая строка одной капсулой во всю ширину. --}}
        @if ($surface !== \App\Support\Surface::Site)
            <div class="header-row">
                <a href="{{ \App\Support\Surface::Site->url() }}" class="header-btn header-h min-w-0 flex-1 px-4 text-sm sm:px-5" data-turbo="false"><span class="truncate">На сайт xcar.ru</span></a>
                <a href="/" class="header-btn-square flex h-[1.875rem] w-[1.875rem] shrink-0 items-center justify-center sm:w-auto" aria-label="XCar"><x-ui.logo responsive class="h-[1.875rem] w-auto"/></a>
            </div>
        @else
            <div class="flex flex-col gap-1">
                <div class="header-row">
                    <a href="/company" class="header-btn header-h min-w-0 flex-1 px-4 text-sm sm:px-5"><span class="truncate">О компании</span></a>
                    <a href="/privacy" class="header-btn header-h min-w-0 flex-1 px-4 text-sm sm:px-5 hidden sm:inline-flex"><span class="truncate">Обработка данных</span></a>
                    <a href="/terms" class="header-btn header-h min-w-0 flex-1 px-4 text-sm sm:px-5"><span class="truncate">Соглашение</span></a>
                    <a href="/" class="header-btn-square flex h-[1.875rem] w-[1.875rem] shrink-0 items-center justify-center sm:w-auto" aria-label="XCar"><x-ui.logo responsive class="h-[1.875rem] w-auto"/></a>
                </div>
                <div class="header-row sm:hidden">
                    <a href="/privacy" class="header-btn header-h min-w-0 flex-1 px-4 text-sm sm:px-5"><span class="truncate">Обработка данных</span></a>
                </div>
            </div>
        @endif
        <p class="nums mt-6 text-sm font-normal text-ink-dim">© XCAR {{ date('Y') }}</p>
    </div>
</footer>
