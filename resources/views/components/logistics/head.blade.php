{{-- Шапка таблицы «Вывоза» — столбцы `TransportController::columns`, те же, что у строки (`x-logistics.row`). Справа одна
     дата: на «Без исполнителя» и «У логиста» — когда поступила, на остальных — «Когда» (поступила — от 1536 у ведущих); город — от 1536 (он и в чипе «Регион»). --}}
@props(['cols', 'sort'])
@php $has = fn (string $k) => in_array($k, $cols, true); @endphp
<tr>
    @if ($has('pick'))<th class="pick-cell"></th>@endif
    <x-ui.th :sort="$sort" key="title" class="grow">Марка, модель</x-ui.th>
    @if ($has('vendor'))<x-ui.th :sort="$sort" key="vendor" class="hidden sm:table-cell">Вендор, № убытка</x-ui.th>@endif
    @if ($has('sale'))<x-ui.th :sort="$sort" key="sale" class="hidden sm:table-cell">Продажа</x-ui.th>@endif
    @if ($has('to'))<th class="hidden sm:table-cell">Кому везти</th>@endif
    @if ($has('city'))<x-ui.th :sort="$sort" key="city" class="col-detail-hide hidden 2xl:table-cell">Город</x-ui.th>@endif
    @if ($has('step'))<th class="hidden sm:table-cell">Что дальше</th>@endif
    @if ($has('who'))<x-ui.th :sort="$sort" key="who" class="hidden sm:table-cell">Кто вывозит</x-ui.th>@endif
    @if ($has('created'))<x-ui.th :sort="$sort" key="created" @class(['num', 'col-detail-hide hidden 2xl:table-cell' => $has('when')])>Поступила</x-ui.th>@endif
    @if ($has('when'))<x-ui.th :sort="$sort" key="when" class="num">Когда</x-ui.th>@endif
</tr>
