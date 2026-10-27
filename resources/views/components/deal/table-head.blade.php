{{-- Шапка таблицы сделок (видна от 640); столбцы те же, что в x-deal.table-row. sort — заголовки сортируют нажатием. --}}
@props(['sort' => null])
<tr>
    <th class="grow title-cap">Марка, модель</th>
    <th class="hidden sm:table-cell">Вендор, № убытка</th>
    <th class="cell-dim hidden 2xl:table-cell">№</th>
    <th class="hidden sm:table-cell">Менеджер</th>
    <th class="fill hidden sm:table-cell">Этап</th>
    <x-ui.th :sort="$sort" key="deadline" class="hidden sm:table-cell">Что сейчас</x-ui.th>
    <x-ui.th :sort="$sort" key="amount" class="num">Сумма</x-ui.th>
</tr>
