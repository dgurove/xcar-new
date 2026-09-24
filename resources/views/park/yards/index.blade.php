{{-- Площадки в три вида: плитка — название, адрес, чип свободных и карта мест; строка — то же без карты (нажатие —
     шторка с картой и формой); таблица — столбцы и окошко строки. Чип занятых мест ведёт к ТС. Цифр-итогов нет. --}}
@php use App\Support\ListView; $admin = auth()->user()->isAdmin(); $view = ListView::pick(request(), $yards->count()) ?? ListView::GRID; $free = fn ($y) => $y->capacity ? max(0, $y->capacity - $y->stored_vehicles_count) : null; @endphp
<x-ui.shell title="Парковки" :count="$yards->count()">
    <x-ui.toolbar :pills="['open' => 'Открытые', 'all' => 'Все']" :pill="$closed ? 'all' : 'open'" pill-param="closed" :counts="['all' => $closedCount ?: null]" :hidden="array_filter([ListView::PARAM => request(ListView::PARAM)])" name="yards">
        <x-slot:extra>
            <x-ui.view-switch :current="$view"/>
            {{-- Заводит и правит парковки только админ: управляющему это настройки. --}}
            @if ($admin)
            <span data-controller="sheet" class="contents">
                <button type="button" class="btn btn-s btn-accent shrink-0 rounded-full" data-action="sheet#open"><x-ui.icon name="plus" class="size-4"/><span class="hidden sm:inline">Парковка</span></button>
                <x-ui.sheet id="yard-new" title="Новая парковка">@include('park.yards.form', ['yard' => null])</x-ui.sheet>
            </span>
            @endif
        </x-slot:extra>
    </x-ui.toolbar>
    @if ($yards->isEmpty())
        <x-ui.empty class="mt-6">Площадок нет</x-ui.empty>
    @elseif (ListView::isTable($view))
        <x-ui.table id="yards" class="mt-6" :view="$view">
            <x-slot:head><tr><th class="grow">Парковка</th><th class="cell-dim hidden sm:table-cell">Город</th><th class="num hidden sm:table-cell">Мест</th><th class="num">Занято</th><th class="num">Свободно</th></tr></x-slot:head>
            @foreach ($yards as $yard)
                <tr id="yard-{{ $yard->id }}" data-peek-url="/yards/{{ $yard->id }}/peek" data-href="/cars?yard={{ $yard->id }}" tabindex="0" class="{{ $yard->is_active ? '' : 'text-ink-dim' }}">
                    <td class="grow">
                        <span class="cell-title">{{ $yard->name }}</span>
                        <span class="cell-sub">@unless ($yard->is_active)<span>закрыта</span>@endunless<span class="sm:hidden">{{ trim(($yard->settlement?->name ?? '').', '.($yard->address ?? ''), ', ') }}</span></span>
                    </td>
                    <td class="cell-dim hidden sm:table-cell">{{ trim(($yard->settlement?->name ?? '').', '.($yard->address ?? ''), ', ') }}</td>
                    <td class="num nums hidden sm:table-cell">{{ $yard->capacity ?: '' }}</td>
                    <td class="num nums">{{ $yard->stored_vehicles_count }}</td>
                    <td class="num nums {{ $free($yard) === 0 ? 'text-danger' : '' }}">{{ $free($yard) ?? '' }}</td>
                </tr>
            @endforeach
        </x-ui.table>
    @elseif ($view === ListView::LIST)
        <div class="list mt-6">
            @foreach ($yards as $yard)
                <div data-controller="sheet">
                    <button type="button" class="row w-full text-left" data-action="sheet#open">
                        <div class="min-w-0 flex-1">
                            <div class="truncate">{{ $yard->name }}</div>
                            <div class="row-sub">@unless ($yard->is_active)<span class="text-ink">закрыта</span>@endunless<span>{{ trim(($yard->settlement?->name ?? '').', '.($yard->address ?? ''), ', ') }}</span>@if ($yard->capacity)<span class="nums {{ $free($yard) === 0 ? 'text-danger' : '' }}">{{ $free($yard) }} свободно</span>@endif</div>
                        </div>
                        <span class="nums shrink-0 text-ink-dim">{{ $yard->stored_vehicles_count }}</span>
                        <x-ui.icon name="chevron-right" class="size-5 shrink-0 text-ink-dim"/>
                    </button>
                    <x-ui.sheet id="yard-{{ $yard->id }}" :title="$yard->name" wide>
                        @if ($yard->rows)<div class="mb-4"><x-park.yard-map :yard="$yard" :occupied="$yard->storedVehicles->whereNotNull('spot')->keyBy('spot')"/></div>@endif
                        @if ($admin)@include('park.yards.form', ['yard' => $yard])@endif
                    </x-ui.sheet>
                </div>
            @endforeach
        </div>
    @else
        <div class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($yards as $yard)
                <div class="box flex min-w-0 flex-col gap-3" data-controller="sheet">
                    <div class="flex items-start gap-2">
                        <div class="min-w-0 flex-1">
                            <div class="font-medium">{{ $yard->name }}@unless ($yard->is_active) <x-ui.pill tone="closed" class="!min-h-0 !py-1 text-xs">закрыта</x-ui.pill>@endunless</div>
                            <div class="mt-1 flex flex-wrap items-center gap-1.5">
                                @if ($yard->settlement || $yard->address)<x-ui.place class="tag">{{ trim(($yard->settlement?->name ?? '').', '.($yard->address ?? ''), ', ') }}</x-ui.place>@endif
                                @if ($yard->capacity)<a href="/cars?yard={{ $yard->id }}" class="tag nums {{ $free($yard) === 0 ? 'text-danger' : '' }}">{{ $free($yard) }} свободно</a>@else<a href="/cars?yard={{ $yard->id }}" class="tag nums">{{ $yard->stored_vehicles_count }} ТС</a>@endif
                            </div>
                        </div>
                        @if ($admin)<button type="button" class="btn btn-s btn-quiet btn-round" data-action="sheet#open" aria-label="Изменить"><x-ui.icon name="edit" class="size-5"/></button>@endif
                    </div>
                    @if ($yard->rows)<x-park.yard-map :yard="$yard" :occupied="$yard->storedVehicles->whereNotNull('spot')->keyBy('spot')"/>@endif
                    @if ($admin)<x-ui.sheet id="yard-{{ $yard->id }}" title="Парковка">@include('park.yards.form', ['yard' => $yard])</x-ui.sheet>@endif
                </div>
            @endforeach
        </div>
    @endif
</x-ui.shell>
