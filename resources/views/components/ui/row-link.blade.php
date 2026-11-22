{{-- Ссылка строки таблицы в карточку рядом (App\Support\Detail): тот же список с ?peek=ключ во фрейм detail, адрес меняет
     detail_controller. Стоит на названии; нажатие мимо неё по строке — она же (detail_controller). Без предзагрузки: проведённая
     над таблицей мышь собрала бы десяток карточек. --}}
{{-- sheet — адрес страницы сделки для шторки поверх приложения одного раздела (`Support\DealSheet`, XCAR Pay 22.11.2026): строка
     открывает её, а не карточку рядом; нажатие по строке ловит `deal_sheet_controller` (`data-deal-sheet-row` у строки). --}}
@props(['key', 'with' => [], 'sheet' => null])
@if ($sheet)
<a href="{{ $sheet }}" {{ $attributes->merge(['class' => 'row-link']) }} data-deal-sheet data-turbo="false">{{ $slot }}</a>
@else
<a href="{{ \App\Support\Detail::url($key, $with) }}" {{ $attributes->merge(['class' => 'row-link']) }} data-detail-link data-turbo-frame="detail" data-turbo-prefetch="false">{{ $slot }}</a>
@endif
