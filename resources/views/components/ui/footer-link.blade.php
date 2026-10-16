{{-- Капсула подвала, как в ряду шапки: ряд делится поровну, длинная надпись режется. --}}
@props(['href'])
<a href="{{ $href }}" {{ $attributes->merge(['class' => 'header-btn header-h min-w-0 flex-1 px-4 text-sm sm:px-5']) }}><span class="truncate">{{ $slot }}</span></a>
