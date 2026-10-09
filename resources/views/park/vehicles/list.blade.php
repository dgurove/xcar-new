{{-- Сам список «Наличия»: таблица с карточкам или карточки. Этим же куском отвечает живой поиск
     (заголовок X-List) — он подменяет содержимое #cars целиком. На «Все» без поиска строки собраны
     под заголовками парковок (grouped): столбца «Парковка» нет, «Без парковки» — последней группой.
     Группы — строки того же tbody: карточка листает ↑/↓ по всем строкам подряд, через границы групп. --}}
@php
    use App\Support\ListView;
    $grouped ??= false;
    $byMonth ??= false;
    // Выданные — свои столбцы (архив дела): принята, выдана со сроком стоянки, хранение с деньгами словом.
    $out ??= false;
    $sort ??= \App\Support\Sort::from(request('sort'), \App\Http\Park\VehicleController::SORTS, '-days');
    $groups = match (true) {
        $byMonth => $vehicles->getCollection()->groupBy(fn ($v) => $v->released_at?->format('Y-m') ?? ''),
        $grouped => $vehicles->getCollection()->groupBy(fn ($v) => $v->yard_id ?? 0)->sortBy(fn ($g, $id) => $id ? $g->first()->yard->name : "\u{10FFFF}"),
        default => collect([0 => $vehicles->getCollection()]),
    };
    $groupName = fn ($key, $group) => $byMonth
        ? ($key ? mb_convert_case(\Illuminate\Support\Carbon::createFromFormat('Y-m', $key)->translatedFormat('F Y'), MB_CASE_TITLE) : 'Без даты')
        : ($key ? $group->first()->yard->name : 'Без парковки');
    // Шапка сортирует нажатием (x-ui.th): «Дней» и «Начислено».
@endphp
@if ($vehicles->isEmpty())
    <x-ui.empty class="py-6">{{ $q !== '' ? 'Ничего не нашлось' : 'ТС нет' }}</x-ui.empty>
@else
    @if (ListView::isTable($view))
        <x-ui.table id="vehicles" :view="$view">
            <x-slot:head>
                @if ($out)
                <tr>
                    <th class="grow">Марка, модель</th>
                    <th class="hidden sm:table-cell">Вендор, № убытка</th>
                    <th class="num col-detail-hide hidden lg:table-cell">Принята</th>
                    <x-ui.th :sort="$sort" key="out" class="num">Выдана</x-ui.th>
                    <x-ui.th :sort="$sort" key="amount" class="num">Хранение</x-ui.th>
                </tr>
                @else
                <tr>
                    <th class="grow">Марка, модель</th>
                    <th class="hidden sm:table-cell">Вендор, № убытка</th>
                    <th class="hidden sm:table-cell">Статус</th>
                    <th class="num col-detail-hide hidden lg:table-cell">Принята</th>
                    <x-ui.th :sort="$sort" key="days" class="num">Дней</x-ui.th>
                    <th class="num hidden sm:table-cell">Ставка</th>
                    <x-ui.th :sort="$sort" key="amount" class="num">Начислено</x-ui.th>
                </tr>
                @endif
            </x-slot:head>
            @php $rows = \App\Park\TableRows::render($vehicles->getCollection(), $totals, $debts, ($q !== '' || $byMonth) && auth()->user()?->seesYards(), $out); @endphp
            @foreach ($groups as $key => $group)
                @if ($grouped || $byMonth)
                    <tr class="table-group"><th colspan="{{ $out ? 5 : 7 }}"><span class="table-group-name">{{ $groupName($key, $group) }} <span class="nums">{{ $group->count() }}</span></span></th></tr>
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
