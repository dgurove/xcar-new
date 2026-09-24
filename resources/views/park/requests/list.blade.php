{{-- Сам список заявок: таблица с окошком или карточки, в конце хвост со следующей порцией — его ловит
     endless_controller. Страниц у заявок нет. Этим же куском отвечает лента (заголовок X-List).
     Таблица при сортировке «По сроку» собрана под заголовками, как «Напоминания»: Просрочено, Сегодня, Завтра,
     Позже, Без срока. Таблица целиком на одной странице, поэтому заголовок группы не повторится в ленте. --}}
@php
    use App\Support\ListView;
    $sort ??= request('sort', 'planned');
    $groups = collect([null => $requests->getCollection()]);
    if (ListView::isTable($view) && $sort === 'planned') {
        $today = now()->startOfDay();
        $groups = $requests->getCollection()->groupBy(fn ($r) => match (true) {
            $r->isOverdue() => 'Просрочено',
            ! $r->planned_at => 'Без срока',
            $r->planned_at->isSameDay($today) => 'Сегодня',
            $r->planned_at->isSameDay($today->copy()->addDay()) => 'Завтра',
            default => 'Позже',
        })->sortBy(fn ($g, $name) => array_search($name, ['Просрочено', 'Сегодня', 'Завтра', 'Позже', 'Без срока'], true));
    }
@endphp
@if ($requests->isEmpty())
    <x-ui.empty class="py-6">{{ request('q') || request('vendor') || request('yard') || request('mine') ? 'Ничего не нашлось' : 'Всё сделано' }}</x-ui.empty>
@elseif (ListView::isTable($view))
    <x-ui.table id="requests-table" :view="$view">
        <x-slot:head>
            <tr><th class="grow">Марка, модель</th><th class="cell-dim hidden sm:table-cell">№ убытка</th><th class="hidden sm:table-cell">Тип</th><th class="num">Срок</th><th class="cell-dim col-peek-hide hidden lg:table-cell">Исполнитель</th><th class="cell-dim col-peek-hide hidden lg:table-cell">Парковка</th></tr>
        </x-slot:head>
        @foreach ($groups as $name => $group)
            @if ($name)<tr class="table-group"><th colspan="6"><span class="table-group-name">{{ $name }} <span class="nums">{{ $group->count() }}</span></span></th></tr>@endif
            @foreach ($group as $r)<x-park.request-row :req="$r"/>@endforeach
        @endforeach
    </x-ui.table>
@else
    @foreach ($requests as $r)<x-park.request-card :req="$r"/>@endforeach
@endif
@if ($requests->hasMorePages())
    <div class="col-span-full py-6 text-center text-sm text-ink-dim" data-endless-next="{{ $requests->appends(request()->query())->nextPageUrl() }}">Ещё заявки…</div>
@endif
