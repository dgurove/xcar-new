{{-- Хлебные крошки: [label, href] … последняя без ссылки. Одна крошка не рисуется. --}}
@props(['trail' => []])
@if (count($trail) > 1)
    <nav aria-label="Хлебные крошки" class="crumbs">
        @foreach ($trail as $crumb)
            @if (!$loop->last && !empty($crumb[1]))
                <a href="{{ $crumb[1] }}">{{ $crumb[0] }}</a><span>/</span>
            @else
                <span>{{ $crumb[0] }}</span>
            @endif
        @endforeach
    </nav>
@endif
