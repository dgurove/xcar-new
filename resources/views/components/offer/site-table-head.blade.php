{{-- Шапка таблицы предложений на сайте (видна от 640); столбцы те же, что в x-offer.site-table-row. sort — заголовки
     сортируют нажатием (x-ui.th). deadline=false — без столбца «Приём» (покупателю срок ни о чём, столбец стоял пустым).
     «Прошло» — с тех пор, как предложение открылось человеку (волна, показ): там, где есть сортировка «Новые показы». --}}
@props(['sort' => null, 'deadline' => true])
<tr>
    <th class="grow">Марка, модель</th>
    <th class="cell-dim hidden sm:table-cell">№</th>
    @if ($deadline)<x-ui.th :sort="$sort" key="closing" class="hidden sm:table-cell">Приём</x-ui.th>@endif
    <th class="cell-dim col-detail-hide hidden lg:table-cell">Город</th>
    <x-ui.th :sort="$sort" key="price" class="num">Цена</x-ui.th>
    <x-ui.th :sort="$sort" :key="$sort?->has('opened') ? 'opened' : 'published'" class="num col-detail-hide hidden sm:table-cell">Прошло</x-ui.th>
</tr>
