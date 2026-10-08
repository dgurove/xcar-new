{{-- Шапка таблицы CRM «Галереи» — столбцы x-offer.gallery-row. --}}
@props(['sort' => null])
<tr>
    <th class="grow title-cap">Марка, модель</th>
    <th class="hidden sm:table-cell">Вендор, № убытка</th>
    <th class="col-detail-hide hidden sm:table-cell">Город</th>
    <x-ui.th :sort="$sort" key="fresh" class="num col-detail-hide hidden sm:table-cell">Появилось</x-ui.th>
    <x-ui.th :sort="$sort" key="closing" class="fill hidden sm:table-cell">Осталось</x-ui.th>
    <x-ui.th :sort="$sort" key="interest" class="num">Интерес</x-ui.th>
    <x-ui.th :sort="$sort" key="last" class="num col-detail-hide hidden sm:table-cell">Отклик</x-ui.th>
</tr>
