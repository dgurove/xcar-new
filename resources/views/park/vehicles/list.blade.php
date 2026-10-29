{{-- Сам список «Наличия»: таблица с карточкам или карточки. Этим же куском отвечает живой поиск
     (заголовок X-List) — он подменяет содержимое #cars целиком. Без поиска строки собраны под заголовками
     парковок (grouped), «Без парковки» — последней группой; столбец «Парковка» есть всегда.
     Группы — строки того же tbody: карточка листает ↑/↓ по всем строкам подряд, через границы групп. --}}
@php
    use App\Support\ListView;
    $grouped ??= false;
    $byMonth ??= false;
    // Выданные — свои столбцы (архив дела): принята, выдана со сроком стоянки, хранение с деньгами словом.
    $out ??= false;
    $sort ??= \App\Support\Sort::from(request('sort'), \App\Http\Park\VehicleController::SORTS, $out ? '-out' : 'accepted');
    $flat ??= false;
    // Приёмщику денег нет (`User::parkMoney`): столбцы ставки, начисленного и хранения не рисуются.
    $money ??= auth()->user()?->parkMoney() ?? true;
    $groups = match (true) {
        $byMonth => $vehicles->getCollection()->groupBy(fn ($v) => $v->released_at?->format('Y-m') ?? ''),
        $grouped => $vehicles->getCollection()->groupBy(fn ($v) => $v->yard_id ?? 0)->sortBy(fn ($g, $id) => $id ? $g->first()->yard->name : "\u{10FFFF}"),
        default => collect([0 => $vehicles->getCollection()]),
    };
    $groupName = fn ($key, $group) => $byMonth
        ? ($key ? mb_convert_case(\Illuminate\Support\Carbon::createFromFormat('Y-m', $key)->translatedFormat('F Y'), MB_CASE_TITLE) : 'Без даты')
        : ($key ? $group->first()->yard->name : 'Без парковки');
    // Шапка сортирует нажатием (x-ui.th) — каждый столбец (12.10.2026, владелец: «как в CRM»); столбцы — одно описание.
    // Подробная таблица — ещё и документы ТС (`VehicleController::table`).
    $wide = $view === ListView::WIDE;
    $columns = \App\Http\Park\VehicleController::table($out, $money ?? true, $wide);
@endphp
@if ($vehicles->isEmpty())
    <x-ui.empty class="py-6">{{ $q !== '' ? 'Ничего не нашлось' : 'ТС нет' }}</x-ui.empty>
@else
    @if (ListView::isTable($view))
        <x-ui.table :table="$columns" id="vehicles" :view="$view" :rest="$columns->rest()" data-controller="table-scroll group-fold" data-group-fold-name-value="park-fold">
            <x-slot:head><x-table.head :table="$columns" :sort="$sort"/></x-slot:head>
            @php $rows = \App\Park\TableRows::render($vehicles->getCollection(), $totals, $debts, $wide, $out, $money ?? true); @endphp
            @foreach ($groups as $key => $group)
                @if ($grouped || $byMonth)
                    {{-- Заголовок группы сворачивает её (12.10.2026, владелец): память по парковке или месяцу в браузере. --}}
                    <x-table.group :table="$columns" :key="($byMonth ? 'm' : 'y').$key" :title="$groupName($key, $group)" :count="$group->count()" class="is-foldable" data-group-fold-target="head" data-fold-key="{{ $byMonth ? 'm' : 'y' }}{{ $key }}" data-action="click->group-fold#toggle"><x-ui.icon name="chevron-down" class="fold-chevron size-4"/></x-table.group>
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
