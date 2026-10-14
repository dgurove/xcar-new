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
    $sort ??= \App\Support\Sort::from(request('sort'), \App\Http\Park\VehicleController::SORTS, $out ? '-out' : 'accepted');
    $flat ??= false;
    $groups = match (true) {
        $byMonth => $vehicles->getCollection()->groupBy(fn ($v) => $v->released_at?->format('Y-m') ?? ''),
        $grouped => $vehicles->getCollection()->groupBy(fn ($v) => $v->yard_id ?? 0)->sortBy(fn ($g, $id) => $id ? $g->first()->yard->name : "\u{10FFFF}"),
        default => collect([0 => $vehicles->getCollection()]),
    };
    $groupName = fn ($key, $group) => $byMonth
        ? ($key ? mb_convert_case(\Illuminate\Support\Carbon::createFromFormat('Y-m', $key)->translatedFormat('F Y'), MB_CASE_TITLE) : 'Без даты')
        : ($key ? $group->first()->yard->name : 'Без парковки');
    // Шапка сортирует нажатием (x-ui.th) — каждый столбец (12.10.2026, владелец: «как в CRM»).
@endphp
@if ($vehicles->isEmpty())
    <x-ui.empty class="py-6">{{ $q !== '' ? 'Ничего не нашлось' : 'ТС нет' }}</x-ui.empty>
@else
    @if (ListView::isTable($view))
        <x-ui.table id="vehicles" :view="$view" data-controller="table-scroll group-fold" data-group-fold-name-value="park-fold">
            <x-slot:head>
                @if ($out)
                <tr>
                    <x-ui.th :sort="$sort" key="name" class="grow">Марка, модель</x-ui.th>
                    <x-ui.th :sort="$sort" key="vendor" class="hidden sm:table-cell">Вендор, № убытка</x-ui.th>
                    <x-ui.th :sort="$sort" key="accepted" class="num col-detail-hide hidden lg:table-cell">Принята</x-ui.th>
                    <x-ui.th :sort="$sort" key="out" class="num">Выдана</x-ui.th>
                    <x-ui.th :sort="$sort" key="amount" class="num">Хранение</x-ui.th>
                </tr>
                @else
                <tr>
                    <x-ui.th :sort="$sort" key="name" class="grow">Марка, модель</x-ui.th>
                    <x-ui.th :sort="$sort" key="vendor" class="hidden sm:table-cell">Вендор, № убытка</x-ui.th>
                    <x-ui.th :sort="$sort" key="tasks" class="hidden sm:table-cell">Статус</x-ui.th>
                    <x-ui.th :sort="$sort" key="accepted" class="num col-detail-hide hidden lg:table-cell">Принята</x-ui.th>
                    <x-ui.th :sort="$sort" key="days" class="num">Дней</x-ui.th>
                    <x-ui.th :sort="$sort" key="rate" class="num hidden sm:table-cell">Ставка</x-ui.th>
                    <x-ui.th :sort="$sort" key="amount" class="num">Начислено</x-ui.th>
                </tr>
                @endif
            </x-slot:head>
            @php $rows = \App\Park\TableRows::render($vehicles->getCollection(), $totals, $debts, ($q !== '' || $byMonth || $flat) && auth()->user()?->seesYards(), $out); @endphp
            @foreach ($groups as $key => $group)
                @if ($grouped || $byMonth)
                    {{-- Заголовок группы сворачивает её (12.10.2026, владелец): память по парковке или месяцу в браузере. --}}
                    <tr class="table-group is-foldable" data-group-fold-target="head" data-fold-key="{{ $byMonth ? 'm' : 'y' }}{{ $key }}" data-action="click->group-fold#toggle"><th colspan="{{ $out ? 5 : 7 }}"><span class="table-group-name">{{ $groupName($key, $group) }} <span class="nums">{{ $group->count() }}</span></span><x-ui.icon name="chevron-down" class="fold-chevron size-4"/></th></tr>
                @endif
                @foreach ($group as $vehicle){!! $rows[$vehicle->id] !!}@endforeach
            @endforeach
        </x-ui.table>
    @elseif ($view === ListView::SHELF && ($grouped || $byMonth))
        {{-- «Лентами» пачка парковки (или месяца выбытия) — лентой вбок (x-ui.shelf, 11.10.2026); «Плитками» — одна сетка ниже. --}}
        <div data-controller="ticker">
            @foreach ($groups as $key => $group)
                <x-ui.shelf :title="$groupName($key, $group)" :count="$group->count()">
                    @foreach ($group as $vehicle)<x-park.card :vehicle="$vehicle" :debt="$debts[$vehicle->id] ?? 0" :total="$totals[$vehicle->id] ?? null"/>@endforeach
                </x-ui.shelf>
            @endforeach
        </div>
    @else
        <div class="{{ ListView::containerClass($view) }}" data-controller="ticker">
            @foreach ($vehicles as $vehicle)<x-park.card :vehicle="$vehicle" :debt="$debts[$vehicle->id] ?? 0" :total="$totals[$vehicle->id] ?? null"/>@endforeach
        </div>
    @endif
    @if ($vehicles->hasPages())<div class="mt-6"><x-ui.pager :of="$vehicles" :sizes="ListView::perSizes($view)"/></div>@endif
@endif
