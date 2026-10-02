{{-- Шапка таблицы предложений (видна от 640); столбцы те же, что в x-offer.table-row. --}}
@props(['gallery' => false])
<tr>
    <th class="grow">Марка, модель</th>
    <th class="hidden sm:table-cell">Вендор, № убытка</th>
    <th class="cell-dim hidden sm:table-cell">№</th>
    <th class="hidden sm:table-cell">Состояние</th>
    {{-- Подтверждения принимает только админ — модератору столбца нет. --}}
    @if (auth()->user()?->canManageCrm())<th class="num hidden sm:table-cell">{{ $gallery ? 'Интерес' : 'Подтверждения' }}</th>@endif
    <th class="num">Цена</th>
    <th class="num col-peek-hide hidden sm:table-cell">Закупочная</th>
</tr>
