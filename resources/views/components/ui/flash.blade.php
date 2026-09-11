{{-- Сообщение в потоке страницы. tone: open, danger, accent, urgent. --}}
@props(['tone' => 'open'])
<p {{ $attributes->merge(['class' => 'flash flash-'.$tone]) }}>{{ $slot }}</p>
