{{-- Машины гаража в пяти видах, как остальные списки сайта (10.10.2026): строки, ленты и плитки (`x-garage.card`), таблица и
     подробная (`x-garage.table-row`). Группы во всех видах: «В гараже» — сначала то, где ход менеджера, дальше по пути,
     внутри дольше стоящие первыми; «Рассчитались» — ниже. Вывозы, порученные менеджеру, — в логистике (17.10.2026). Строка — кадр,
     название, этап цветным словом с днями (ждут ответа — «ваш ход» и что сделать) и главное число справа (`CarLine`).
     До продажи главное число — закупочная (13.10.2026, владелец: её видят все). Сотрудник видит всех и чьи. Нажатие —
     страница машины. «Стоят у вас» (21.10.2026) — машины, что стоят у менеджера без его сделки (`offers.held_by`): продаёт
     XCar, забирать будут у него. --}}
@php
    use App\Garage\CarLine;
    use App\Garage\CarState;
    use App\Support\ListView;
    $user = auth()->user();
    $staff = $user->isAdmin();
    $lines = $cars->mapWithKeys(fn ($c) => [$c->id => CarLine::for($c, $user)]);
    [$closed, $open] = $cars->partition(fn ($c) => $c->state === CarState::Settled);
    $open = $open->sortBy(fn ($c) => match ($sort->key) {
        'fresh' => [$c->created_at?->timestamp ?? 0, $c->id],
        'days' => [$c->stageDays(), $c->id],
        'amount' => [(float) $lines[$c->id]->value, $c->id],
        'price' => [(float) $c->priceFor($staff), $c->id],
        default => [$c->deal?->openRequirement ? 0 : 1, $c->state->order(), $c->stage_at?->timestamp ?? 0],
    }, SORT_REGULAR, $sort->desc)->values();
    $count = $open->count();
    // Гараж по умолчанию — строками (11.10.2026, владелец), не плитками, как остальные списки сайта.
    $view = ListView::fromRequest(request(), shelves: true) ?? ListView::LIST;
    $carRow = fn ($car) => ['offer' => $car->offer, 'href' => '/garage/cars/'.$car->offer->number, 'word' => $lines[$car->id]->word,
        'tone' => $lines[$car->id]->tone, 'when' => $lines[$car->id]->when, 'value' => $lines[$car->id]->value,
        'caption' => $lines[$car->id]->caption, 'who' => $lines[$car->id]->who];
    // Стоят у менеджера без его сделки (21.10.2026): чинить не стал, продаёт XCar — строка без денег и этапов, ведёт на ТС.
    $heldRows = $held->map(fn ($o) => ['offer' => $o, 'href' => $o->isVisibleTo($user) ? '/offers/'.$o->number : '/garage', 'word' => 'продаёт XCar', 'tone' => 'text-ink-muted',
        'when' => null, 'value' => null, 'caption' => null, 'who' => $staff ? $o->heldBy?->shortName() : null, 'car' => null]);
    $groups = array_filter([
        '' => $open->map(fn ($c) => $carRow($c) + ['car' => $c]),
        ($staff ? 'Стоят у менеджеров' : 'Стоят у вас') => $heldRows,
        'Рассчитались' => $closed->map(fn ($c) => $carRow($c) + ['car' => $c]),
    ], fn ($g) => $g->isNotEmpty());
@endphp
<x-ui.shell title="Гараж" :count="$count ?: null">
    {{-- На телефоне сортировка и вид — справа от заголовка, а не своим рядом под ним (09.10.2026); на ПК — над списком. --}}
    @if ($groups)
        <x-slot:actions class="!w-auto ml-auto md:hidden"><x-ui.toolbar :sort="$sort" name="garage-head"><x-slot:extra><x-ui.view-switch :current="$view" :views="ListView::SHELVED"/></x-slot:extra></x-ui.toolbar></x-slot:actions>
        <x-ui.toolbar :sort="$sort" name="garage" class="mb-4 max-md:hidden"><x-slot:extra><x-ui.view-switch :current="$view" :views="ListView::SHELVED"/></x-slot:extra></x-ui.toolbar>
    @endif
    @if (! $groups)
        <x-ui.empty class="mt-2">В гараже пусто</x-ui.empty>
    @elseif (ListView::isTable($view))
        <div data-controller="row-go" data-action="click->row-go#go">
            @php $columns = \App\Http\Garage\CarController::table($user, $staff); @endphp
            <x-ui.table :table="$columns" id="garage-table" :view="$view">
                <x-slot:head><x-table.head :table="$columns" :sort="$sort"/></x-slot:head>
                @foreach ($groups as $head => $rows)
                    @if ($head)<x-table.group :table="$columns" :key="'g'.md5($head)" :title="$head" :count="$rows->count()"/>@endif
                    @foreach ($rows as $row)<x-garage.table-row :table="$columns" :staff="$staff" :offer="$row['offer']" :href="$row['href']" :word="$row['word']" :tone="$row['tone']" :when="$row['when']" :value="$row['value'] ?? null" :caption="$row['caption'] ?? null" :who="$row['who'] ?? null" :car="$row['car'] ?? null"/>@endforeach
                @endforeach
            </x-ui.table>
        </div>
    @elseif ($view === ListView::GRID)
        {{-- «Плитками» (15.10.2026, начальник): одна сетка без подписей групп и лент вбок. --}}
        <div class="cards cards--grid" data-controller="ticker">
            @foreach (collect($groups)->flatten(1) as $row)<x-garage.card :offer="$row['offer']" :href="$row['href']" :word="$row['word']" :tone="$row['tone']" :when="$row['when']" :value="$row['value'] ?? null" :caption="$row['caption'] ?? null" :who="$row['who'] ?? null"/>@endforeach
        </div>
    @elseif ($view === ListView::SHELF)
        {{-- «Лентами»: группа — лентой вбок (x-ui.shelf, 11.10.2026). --}}
        <div class="flex flex-col" data-controller="ticker">
            @foreach ($groups as $head => $rows)
                {{-- Одна группа без подписи (только машины гаража) — сеткой: одна бесконечная строка вбок хуже. --}}
                @if ($head)
                    <x-ui.shelf :title="$head" :count="$rows->count()">@foreach ($rows as $row)<x-garage.card :offer="$row['offer']" :href="$row['href']" :word="$row['word']" :tone="$row['tone']" :when="$row['when']" :value="$row['value'] ?? null" :caption="$row['caption'] ?? null" :who="$row['who'] ?? null"/>@endforeach</x-ui.shelf>
                @else
                    <div class="cards cards--grid mb-6">@foreach ($rows as $row)<x-garage.card :offer="$row['offer']" :href="$row['href']" :word="$row['word']" :tone="$row['tone']" :when="$row['when']" :value="$row['value'] ?? null" :caption="$row['caption'] ?? null" :who="$row['who'] ?? null"/>@endforeach</div>
                @endif
            @endforeach
        </div>
    @else
        {{-- Строки — сгруппированный список `.list`. --}}
        <div class="flex flex-col">
            @foreach ($groups as $head => $rows)
                @if ($head)<h2 class="list-head">{{ $head }}<span class="nums text-base font-normal text-ink-muted">{{ $rows->count() }}</span></h2>@endif
                <div class="list mb-4">
                    @foreach ($rows as $row)<x-garage.row :offer="$row['offer']" :href="$row['href']" :word="$row['word']" :tone="$row['tone']" :when="$row['when']" :value="$row['value'] ?? null" :caption="$row['caption'] ?? null" :who="$row['who'] ?? null"/>@endforeach
                </div>
            @endforeach
        </div>
    @endif
</x-ui.shell>
