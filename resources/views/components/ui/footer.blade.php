{{-- Подвал: ряд капсул как в шапке (О компании, документы) и знак. --}}
@php $surface = \App\Support\Surface::current(); @endphp
<footer class="footer relative z-10 bg-bar pt-12 text-ink">
    <div class="container-site">
        {{-- Ряды шапки: капсулы делят ряд поровну и упираются в знак прямым краем. ПК — один ряд и горизонтальный
             логотип; телефон — два ряда и квадратный знак высотой в обе строки. --}}
        @if ($surface !== \App\Support\Surface::Site)
            <div class="header-row">
                <a href="{{ \App\Support\Surface::Site->url() }}" class="header-btn header-h min-w-0 flex-1 px-4 text-sm sm:px-5" data-turbo="false"><span class="truncate">На сайт xcar.ru</span></a>
                <a href="{{ auth()->check() ? \App\Support\Surface::current()->home() : '/' }}" class="header-btn-square flex h-[1.875rem] w-[1.875rem] shrink-0 items-center justify-center sm:w-auto" aria-label="XCar"><x-ui.logo responsive class="h-[1.875rem] w-auto"/></a>
            </div>
        @else
            <div class="grid grid-cols-[1fr_auto] gap-1 sm:hidden">
                <div class="header-row header-row--mark">
                    <a href="/company" class="header-btn header-h min-w-0 flex-1 px-4 text-sm sm:px-5"><span class="truncate">О компании</span></a>
                    <a href="/terms" class="header-btn header-h min-w-0 flex-1 px-4 text-sm sm:px-5"><span class="truncate">Соглашение</span></a>
                </div>
                <a href="{{ auth()->check() ? \App\Support\Surface::current()->home() : '/' }}" class="row-span-2 flex size-16 items-center justify-center" aria-label="XCar"><x-ui.logo mark class="size-16"/></a>
                <div class="header-row header-row--mark header-row--square">
                    <a href="/privacy" class="header-btn header-h min-w-0 flex-1 px-4 text-sm sm:px-5"><span class="truncate">Обработка данных</span></a>
                </div>
            </div>
            <div class="header-row hidden sm:flex">
                <a href="/company" class="header-btn header-h min-w-0 flex-1 px-4 text-sm sm:px-5"><span class="truncate">О компании</span></a>
                <a href="/privacy" class="header-btn header-h min-w-0 flex-1 px-4 text-sm sm:px-5"><span class="truncate">Обработка данных</span></a>
                <a href="/terms" class="header-btn header-h min-w-0 flex-1 px-4 text-sm sm:px-5"><span class="truncate">Соглашение</span></a>
                <a href="{{ auth()->check() ? \App\Support\Surface::current()->home() : '/' }}" class="header-btn-square flex h-[1.875rem] shrink-0 items-center justify-center" aria-label="XCar"><x-ui.logo class="h-[1.875rem] w-auto"/></a>
            </div>
        @endif
        <p class="nums mt-6 text-sm font-normal text-ink-dim">© XCAR {{ date('Y') }}</p>
    </div>
</footer>
