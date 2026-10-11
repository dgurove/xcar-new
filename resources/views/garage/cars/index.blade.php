{{-- Машины гаража в четырёх видах, как остальные списки сайта (10.10.2026): строки, плитки (`x-garage.card`), таблица и
     подробная (`x-garage.table-row`). Группы во всех видах: «Вывоз» — ТС, что менеджеру поручили забрать или что стоят у
     него (не его гараж: продаёт не обязательно он; машина, которая уже в гараже, второй раз здесь не стоит), «В гараже» —
     сначала то, где ход менеджера, дальше по пути, внутри дольше стоящие первыми; «Рассчитались» — ниже. Строка — кадр,
     название, этап цветным словом с днями (ждут ответа — «ваш ход» и что сделать) и главное число справа (`CarLine`).
     Менеджер закупочную не видит: у него расходы, а не «вложено». Сотрудник видит всех и чьи. Нажатие — страница машины. --}}
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
        default => [$c->deal?->openRequirement ? 0 : 1, $c->state->order(), $c->stage_at?->timestamp ?? 0],
    }, SORT_REGULAR, $sort->desc)->values();
    $count = $open->count() + $pickups->count();
    // Гараж по умолчанию — строками (11.10.2026, владелец), не плитками, как остальные списки сайта.
    $view = ListView::fromRequest(request()) ?? ListView::LIST;
    // Строка вывоза — те же поля, что у машины: слово вывоза цветом и дни.
    $pickupRow = function ($offer) use ($user) {
        [$word, $tone] = \App\Offers\PickupState::of($offer, $user);
        $d = \App\Offers\PickupState::days($offer);

        return ['offer' => $offer, 'href' => '/garage/pickups/'.$offer->number, 'word' => $word,
            'tone' => match ($tone) { 'urgent' => 'text-urgent', 'open' => 'text-accent-text', default => 'text-ink-muted' },
            'when' => $d ? CarLine::days($d) : null];
    };
    $carRow = fn ($car) => ['offer' => $car->offer, 'href' => (in_array($car->offer_id, $picking, true) ? '/garage/pickups/' : '/garage/cars/').$car->offer->number, 'word' => $lines[$car->id]->word,
        'tone' => $lines[$car->id]->tone, 'when' => $lines[$car->id]->when, 'value' => $lines[$car->id]->value,
        'caption' => $lines[$car->id]->caption, 'who' => $lines[$car->id]->who];
    // Над «Вывозом» своя группа машин гаража тоже подписана — иначе она читалась бы продолжением вывоза.
    $groups = array_filter([
        'Вывоз' => $pickups->map($pickupRow),
        ($pickups->isNotEmpty() ? 'В гараже' : '') => $open->map(fn ($c) => $carRow($c) + ['car' => $c]),
        'Рассчитались' => $closed->map(fn ($c) => $carRow($c) + ['car' => $c]),
    ], fn ($g) => $g->isNotEmpty());
@endphp
<x-ui.shell title="Гараж" :count="$count ?: null">
    {{-- На телефоне сортировка и вид — справа от заголовка, а не своим рядом под ним (09.10.2026); на ПК — над списком. --}}
    @if ($groups)
        <x-slot:actions class="!w-auto ml-auto md:hidden"><x-ui.toolbar :sort="$sort" name="garage-head"><x-slot:extra><x-ui.view-switch :current="$view"/></x-slot:extra></x-ui.toolbar></x-slot:actions>
        <x-ui.toolbar :sort="$sort" name="garage" class="mb-4 max-md:hidden"><x-slot:extra><x-ui.view-switch :current="$view"/></x-slot:extra></x-ui.toolbar>
    @endif
    @if (! $groups)
        <x-ui.empty class="mt-2">В гараже пусто</x-ui.empty>
    @elseif (ListView::isTable($view))
        <div data-controller="row-go" data-action="click->row-go#go">
            <x-ui.table id="garage-table" :view="$view" :titles="collect($groups)->flatten(1)->map(fn ($r) => $r['offer']->titleWithYear())">
                <x-slot:head>
                    <tr>
                        <th class="grow">Марка, модель</th>
                        @if ($staff)<th class="hidden md:table-cell">Менеджер</th>@endif
                        <th class="num hidden lg:table-cell col-detail-hide">В гараже с</th>
                        <th class="num hidden sm:table-cell">Расходы</th>
                        <th class="num hidden lg:table-cell">Цена продажи</th>
                        <x-ui.th :sort="$sort" key="amount" class="num">Сумма</x-ui.th>
                    </tr>
                </x-slot:head>
                @foreach ($groups as $head => $rows)
                    @if ($head)<tr class="table-group"><th colspan="{{ $staff ? 6 : 5 }}"><span class="table-group-name">{{ $head }} <span class="nums">{{ $rows->count() }}</span></span></th></tr>@endif
                    @foreach ($rows as $row)<x-garage.table-row :staff="$staff" :offer="$row['offer']" :href="$row['href']" :word="$row['word']" :tone="$row['tone']" :when="$row['when']" :value="$row['value'] ?? null" :caption="$row['caption'] ?? null" :who="$row['who'] ?? null" :car="$row['car'] ?? null"/>@endforeach
                @endforeach
            </x-ui.table>
        </div>
    @else
        {{-- Строки — сгруппированный список `.list`, плитки — `x-garage.card`; без выбора вид решает ширина: на телефоне
             строки, от 640 плитки (оба куска в разметке, кадры ленивые — скрытый их не грузит). --}}
        @foreach (array_filter(['list' => $view === ListView::LIST || ! $view, 'grid' => $view === ListView::GRID || ! $view]) as $kind => $_)
            <div @class(['flex flex-col', 'sm:hidden' => ! $view && $kind === 'list', 'max-sm:hidden' => ! $view && $kind === 'grid']) @if ($kind === 'grid') data-controller="ticker" @endif>
                @foreach ($groups as $head => $rows)
                    {{-- Плитками пачка — лентой вбок (x-ui.shelf, 11.10.2026); без пачек — сеткой. --}}
                    @if ($kind === 'grid' && $head)
                        <x-ui.shelf :title="$head" :count="$rows->count()">
                            @foreach ($rows as $row)<x-garage.card :offer="$row['offer']" :href="$row['href']" :word="$row['word']" :tone="$row['tone']" :when="$row['when']" :value="$row['value'] ?? null" :caption="$row['caption'] ?? null" :who="$row['who'] ?? null"/>@endforeach
                        </x-ui.shelf>
                        @continue
                    @endif
                    @if ($head)<h2 class="list-head">{{ $head }}<span class="nums text-base font-normal text-ink-muted">{{ $rows->count() }}</span></h2>@endif
                    @if ($kind === 'list')
                        <div class="list mb-4">
                            @foreach ($rows as $row)<x-garage.row :offer="$row['offer']" :href="$row['href']" :word="$row['word']" :tone="$row['tone']" :when="$row['when']" :value="$row['value'] ?? null" :caption="$row['caption'] ?? null" :who="$row['who'] ?? null"/>@endforeach
                        </div>
                    @else
                        <div class="cards cards--grid mb-6">
                            @foreach ($rows as $row)<x-garage.card :offer="$row['offer']" :href="$row['href']" :word="$row['word']" :tone="$row['tone']" :when="$row['when']" :value="$row['value'] ?? null" :caption="$row['caption'] ?? null" :who="$row['who'] ?? null"/>@endforeach
                        </div>
                    @endif
                @endforeach
            </div>
        @endforeach
    @endif
</x-ui.shell>
