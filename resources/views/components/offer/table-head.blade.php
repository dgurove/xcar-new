{{-- Шапка таблицы предложений (видна от 640); столбцы те же, что в x-offer.table-row. На широком экране название не
     растягивается на полтаблицы (title-cap) — остаток забирает «Состояние» (fill): номер убытка и № стоят слева,
     сразу за названием, а не в правой половине. cols — столбцы вкладки (OfferController::columns), null — все; без
     «Состояния» остаток ширины забирает пустой столбец перед числами: слева всё плотно, числа справа. --}}
@props(['gallery' => false, 'pick' => false, 'cols' => null])
@php
    $has = fn (string $k) => $cols === null || in_array($k, $cols, true);
@endphp
<tr>
    @if ($pick)<th class="pick-cell"></th>@endif
    <th class="grow title-cap">Марка, модель</th>
    @if ($has('vendor'))<th class="hidden sm:table-cell">Вендор, № убытка</th>@endif
    {{-- Карточка рядом — места мало: № и подтверждения видны в ней, из таблицы они уходят (col-detail-hide). --}}
    @if ($has('no'))<th class="cell-dim col-detail-hide hidden sm:table-cell">№</th>@endif
    @if ($has('state'))<th class="fill hidden sm:table-cell">{{ $cols !== null && $has('bids') ? 'Приём' : 'Состояние' }}</th>
    {{-- Без «Состояния» запас ширины уходит в пустой столбец перед числами, а не в промежутки между столбцами. --}}
    @else<th class="fill hidden sm:table-cell"></th>@endif
    {{-- Подтверждения принимает только админ — модератору столбца нет. --}}
    @if ($has('bids') && auth()->user()?->canManageCrm())<th class="num col-detail-hide hidden sm:table-cell">{{ $gallery ? 'Интерес' : 'Подтверждения' }}</th>@endif
    {{-- Сначала закупочная, потом продажи (владелец 04.10.2026: так логичнее). --}}
    @if ($has('floor'))<th class="num hidden sm:table-cell">Закупочная</th>@endif
    @if ($has('price'))<th class="num">Цена</th>@endif
    @if ($cols !== null && $has('published'))<th class="num col-detail-hide hidden sm:table-cell">Вышло</th>
    @elseif ($has('created'))<th class="num col-detail-hide hidden sm:table-cell">Создано</th>@endif
</tr>
