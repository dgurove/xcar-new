{{-- Сам список «Наличия»: таблица с карточкам или карточки. Этим же куском отвечает живой поиск
     (заголовок X-List) — он подменяет содержимое #cars целиком. На «Все» без поиска строки собраны
     под заголовками парковок (grouped): столбца «Парковка» нет, «Без парковки» — последней группой.
     Группы — строки того же tbody: карточка листает ↑/↓ по всем строкам подряд, через границы групп. --}}
@php
    use App\Support\ListView;
    $grouped ??= false;
    $sort ??= \App\Support\Sort::from(request('sort'), \App\Http\Park\VehicleController::SORTS, '-days');
    $groups = $grouped
        ? $vehicles->getCollection()->groupBy(fn ($v) => $v->yard_id ?? 0)->sortBy(fn ($g, $id) => $id ? $g->first()->yard->name : "\u{10FFFF}")
        : collect([0 => $vehicles->getCollection()]);
    // Шапка сортирует нажатием (x-ui.th): «Дней» и «Начислено».
@endphp
@if ($vehicles->isEmpty())
    <x-ui.empty class="py-6">{{ $q !== '' ? 'Ничего не нашлось' : 'ТС нет' }}</x-ui.empty>
@else
    @if (ListView::isTable($view))
        <x-ui.table id="vehicles" :view="$view">
            <x-slot:head>
                <tr>
                    <th class="grow">Марка, модель</th>
                    <th class="hidden sm:table-cell">Вендор, № убытка</th>
                    <th class="hidden sm:table-cell">Статус</th>
                    <th class="num col-detail-hide hidden lg:table-cell">Принята</th>
                    <x-ui.th :sort="$sort" key="days" class="num">Дней</x-ui.th>
                    <th class="num hidden sm:table-cell">Ставка</th>
                    <x-ui.th :sort="$sort" key="amount" class="num">Начислено</x-ui.th>
                </tr>
            </x-slot:head>
            @php $rows = \App\Park\TableRows::render($vehicles->getCollection(), $totals, $debts, $q !== ''); @endphp
            @foreach ($groups as $yardId => $group)
                @if ($grouped)
                    <tr class="table-group"><th colspan="7"><span class="table-group-name">{{ $yardId ? $group->first()->yard->name : 'Без парковки' }} <span class="nums">{{ $group->count() }}</span></span></th></tr>
                @endif
                @foreach ($group as $vehicle){!! $rows[$vehicle->id] !!}@endforeach
            @endforeach
        </x-ui.table>
    @else
        <div class="{{ ListView::containerClass($view) }}" data-controller="ticker">
            @foreach ($vehicles as $vehicle)<x-park.card :vehicle="$vehicle" :debt="$debts[$vehicle->id] ?? 0" :total="$totals[$vehicle->id] ?? null"/>@endforeach
        </div>
    @endif
    @if ($vehicles->hasPages())<div class="mt-6"><x-ui.pager :of="$vehicles" :sizes="ListView::perSizes($view)"/></div>@endif
@endif
