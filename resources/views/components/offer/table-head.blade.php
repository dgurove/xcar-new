{{-- Шапка таблицы предложений (видна от 640); столбцы те же, что в x-offer.table-row. На широком экране название не
     растягивается на полтаблицы (title-cap) — остаток забирает «Состояние» (fill): номер убытка и № стоят слева,
     сразу за названием, а не в правой половине. cols — столбцы вкладки (OfferController::columns), null — все; без
     «Состояния» остаток ширины забирает пустой столбец перед числами: слева всё плотно, числа справа. sort — сортировка
     списка: заголовок с её полем сортирует нажатием (x-ui.th); у галереи дата — «fresh», интерес — «interest». --}}
@props(['gallery' => false, 'pick' => false, 'cols' => null, 'sort' => null])
@php
    $has = fn (string $k) => $cols === null || in_array($k, $cols, true);
@endphp
<tr>
    @if ($pick)<th class="pick-cell"></th>@endif
    <th class="grow title-cap">Марка, модель</th>
    @if ($has('vendor'))<x-ui.th :sort="$sort" key="vendor" class="hidden sm:table-cell">Вендор, № убытка</x-ui.th>@endif
    @if ($has('city'))<th class="col-detail-hide hidden sm:table-cell">Город</th>@endif
    {{-- Карточка рядом — места мало: город, закупочная, оценочная и дата уходят (col-detail-hide, они в карточке), подтверждения
         и цена остаются (владелец 05.10.2026); в подробной таблице не уходит ничего — листается вбок. Столбца «№» нет
         (владелец 04.10.2026): по номеру ищут, а смотреть его в таблице незачем. --}}
    @if ($has('state'))<x-ui.th :sort="$sort" key="closing" class="fill hidden sm:table-cell">{{ $cols !== null && $has('bids') ? 'Приём' : 'Состояние' }}</x-ui.th>
    {{-- Без «Состояния» запас ширины уходит в пустой столбец перед числами, а не в промежутки между столбцами. --}}
    @else<th class="fill hidden sm:table-cell"></th>@endif
    {{-- Подтверждения принимает только админ — модератору столбца нет. --}}
    @if ($has('bids') && auth()->user()?->canManageCrm())<x-ui.th :sort="$sort" :key="$gallery ? 'interest' : 'bids'" class="num hidden sm:table-cell">{{ $gallery ? 'Интерес' : 'Подтверждения' }}</x-ui.th>@endif
    {{-- Сначала закупочная, потом продажи (владелец 04.10.2026: так логичнее). --}}
    {{-- Оценочная — до закупочной: у Альфы закупочная из неё (владелец 05.10.2026: «оценочные тоже надо видеть»). --}}
    @if ($cols !== null && $has('value'))<th class="num col-detail-hide hidden sm:table-cell">Оценочная</th>@endif
    {{-- Без цены продажи («Без закупочной цены») закупочная встаёт на её место: видна всегда, с карточкой рядом тоже. --}}
    @if ($has('floor') && $cols !== null && ! $has('price'))<x-ui.th :sort="$sort" key="floor" class="num">Закупочная</x-ui.th>
    @elseif ($has('floor'))<x-ui.th :sort="$sort" key="floor" class="num col-detail-hide hidden sm:table-cell">Закупочная</x-ui.th>@endif
    @if ($has('price'))<x-ui.th :sort="$sort" key="price" class="num">Цена</x-ui.th>@endif
    @if ($cols !== null && $has('published'))<x-ui.th :sort="$sort" key="published" class="num col-detail-hide hidden sm:table-cell">Вышло</x-ui.th>
    @elseif ($has('created'))<x-ui.th :sort="$sort" :key="$gallery ? 'fresh' : 'created'" class="num col-detail-hide hidden sm:table-cell">Создано</x-ui.th>@endif
</tr>
