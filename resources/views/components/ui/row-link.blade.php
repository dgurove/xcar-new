{{-- Ссылка строки таблицы в карточку рядом (App\Support\Detail): тот же список с ?peek=ключ во фрейм detail, адрес меняет
     Turbo. Стоит на названии; нажатие мимо неё по строке — она же (detail_controller). Без предзагрузки: проведённая
     над таблицей мышь собрала бы десяток карточек. --}}
@props(['key'])
<a href="{{ \App\Support\Detail::url($key) }}" {{ $attributes->merge(['class' => 'row-link']) }} data-detail-link data-turbo-frame="detail" data-turbo-action="replace" data-turbo-prefetch="false">{{ $slot }}</a>
