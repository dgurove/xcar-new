{{-- Сам список «Наличия»: таблица с окошком или карточки. Этим же куском отвечает живой поиск
     (заголовок X-List) — он подменяет содержимое #cars целиком. На «Все» без поиска строки собраны
     под заголовками парковок (grouped): столбца «Парковка» нет, «Без парковки» — последней группой.
     Группы — строки того же tbody: окошко листает ↑/↓ по всем строкам подряд, через границы групп. --}}
@php
    use App\Support\ListView;
    $grouped ??= false;
    $sort ??= request('sort', 'longest');
    $groups = $grouped
        ? $vehicles->getCollection()->groupBy(fn ($v) => $v->yard_id ?? 0)->sortBy(fn ($g, $id) => $id ? $g->first()->yard->name : "\u{10FFFF}")
        : collect([0 => $vehicles->getCollection()]);
    // Шапка (она только на ПК, на телефоне три столбца читаются и так) сортирует нажатием, как столбцы в
    // «Файлах»: сутки — дольше стоят, сумма — больше набежало.
    // Набранный поиск адрес не меняет (живой поиск) — и в ссылку не попадает.
    $sortBy = fn (string $key) => '/cars?'.http_build_query(array_filter(['sort' => $key === 'longest' ? null : $key] + \Illuminate\Support\Arr::except(request()->query(), ['sort', 'page', 'q'])));
@endphp
@if ($vehicles->isEmpty())
    <x-ui.empty class="py-6">{{ $q !== '' ? 'Ничего не нашлось' : 'ТС нет' }}</x-ui.empty>
@else
    @if ($view === ListView::TABLE)
        <x-ui.table id="vehicles" class="table-wrap--park" :open="$peek">
            <x-slot:head>
                <tr>
                    <th class="grow">Марка, модель</th>
                    <th class="park-ref hidden sm:table-cell">№ убытка</th>
                    <th class="park-vendor hidden lg:table-cell">Вендор</th>
                    <th class="num"><a href="{{ $sortBy('longest') }}" data-turbo-action="replace" @if ($sort === 'longest') aria-current="true" @endif>Дней</a></th>
                    <th class="num hidden sm:table-cell">₽/сут</th>
                    <th class="num"><a href="{{ $sortBy('amount') }}" data-turbo-action="replace" @if ($sort === 'amount') aria-current="true" @endif>Начислено</a></th>
                </tr>
            </x-slot:head>
            @foreach ($groups as $yardId => $group)
                @if ($grouped)
                    <tr class="table-group"><th colspan="6">{{ $yardId ? $group->first()->yard->name : 'Без парковки' }} <span class="nums">{{ $group->count() }}</span></th></tr>
                @endif
                @foreach ($group as $vehicle)<x-park.table-row :vehicle="$vehicle" :total="$totals[$vehicle->id] ?? null" :debt="$debts[$vehicle->id] ?? 0" :place="$q !== ''"/>@endforeach
            @endforeach
        </x-ui.table>
    @else
        <div class="{{ ListView::containerClass($view) }}" data-controller="ticker">
            @foreach ($vehicles as $vehicle)<x-park.card :vehicle="$vehicle" :debt="$debts[$vehicle->id] ?? 0" :total="$totals[$vehicle->id] ?? null"/>@endforeach
        </div>
    @endif
    @if ($vehicles->hasPages())<div class="mt-6"><x-ui.pager :of="$vehicles" :sizes="ListView::perSizes($view)"/></div>@endif
@endif
