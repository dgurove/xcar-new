{{-- Подвал: ряд капсул как в шапке (О компании, документы) и знак. --}}
@php
    $surface = \App\Support\Surface::current();
    $home = auth()->check() ? $surface->home() : '/';
@endphp
<footer class="footer relative z-10 bg-bar pt-12 text-ink">
    <div class="container-site">
        {{-- Ряды шапки: капсулы делят ряд поровну и упираются в знак прямым краем. ПК — один ряд и горизонтальный
             логотип; телефон — два ряда и квадратный знак высотой в обе строки. --}}
        @if ($surface !== \App\Support\Surface::Site)
            <div class="header-row">
                <x-ui.footer-link :href="\App\Support\Surface::Site->url()" data-turbo="false">На сайт xcar.ru</x-ui.footer-link>
                <a href="{{ $home }}" class="header-btn-square flex h-[1.875rem] w-[1.875rem] shrink-0 items-center justify-center sm:w-auto" aria-label="XCar"><x-ui.logo responsive class="h-[1.875rem] w-auto"/></a>
            </div>
        @else
            <div class="grid grid-cols-[1fr_auto] gap-1 sm:hidden">
                <div class="header-row header-row--mark">
                    <x-ui.footer-link href="/company">О компании</x-ui.footer-link>
                    <x-ui.footer-link href="/terms">Соглашение</x-ui.footer-link>
                </div>
                <a href="{{ $home }}" class="row-span-2 flex size-16 items-center justify-center" aria-label="XCar"><x-ui.logo mark class="size-16"/></a>
                <div class="header-row header-row--mark header-row--square">
                    <x-ui.footer-link href="/privacy">Обработка данных</x-ui.footer-link>
                </div>
            </div>
            <div class="header-row hidden sm:flex">
                <x-ui.footer-link href="/company">О компании</x-ui.footer-link>
                <x-ui.footer-link href="/privacy">Обработка данных</x-ui.footer-link>
                <x-ui.footer-link href="/terms">Соглашение</x-ui.footer-link>
                <a href="{{ $home }}" class="header-btn-square flex h-[1.875rem] shrink-0 items-center justify-center" aria-label="XCar"><x-ui.logo class="h-[1.875rem] w-auto"/></a>
            </div>
        @endif
        <p class="nums mt-6 text-sm font-normal text-ink-dim">© XCAR {{ date('Y') }}</p>
    </div>
</footer>
