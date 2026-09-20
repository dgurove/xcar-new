{{-- Площадки в три вида: плитка — название, адрес, чип свободных и карта мест; строка — то же без карты (нажатие —
     шторка с картой и формой); таблица — столбцы и окошко строки. Чип занятых мест ведёт к ТС. Цифр-итогов нет. --}}
@php use App\Support\ListView; $view = ListView::pick(request(), $yards->count()) ?? ListView::GRID; $free = fn ($y) => $y->capacity ? max(0, $y->capacity - $y->stored_vehicles_count) : null; @endphp
<x-ui.shell title="Стоянки" :count="$yards->count()">
    <x-ui.toolbar :pills="['open' => 'Открытые', 'all' => 'Все']" :pill="$closed ? 'all' : 'open'" pill-param="closed" :counts="['all' => $closedCount ?: null]" :hidden="array_filter([ListView::PARAM => request(ListView::PARAM)])" name="yards">
        <x-slot:extra>
            <x-ui.view-switch :current="$view"/>
            <span data-controller="sheet" class="contents">
                <button type="button" class="btn btn-s btn-accent shrink-0 rounded-full" data-action="sheet#open"><x-ui.icon name="plus" class="size-4"/><span class="hidden sm:inline">Стоянка</span></button>
                <x-ui.sheet id="yard-new" title="Новая стоянка">@include('park.yards.form', ['yard' => null])</x-ui.sheet>
            </span>
        </x-slot:extra>
    </x-ui.toolbar>
    @if ($yards->isEmpty())
        <x-ui.empty class="mt-6">Площадок нет</x-ui.empty>
    @elseif ($view === ListView::TABLE)
        <x-ui.table id="yards" class="mt-6">
            <x-slot:head><tr><th class="grow">Стоянка</th><th class="hidden sm:table-cell">Город</th><th class="num">Мест</th><th class="num">Занято</th><th class="num">Свободно</th></tr></x-slot:head>
            @foreach ($yards as $yard)
                <tr id="yard-{{ $yard->id }}" data-peek-url="/yards/{{ $yard->id }}/peek" data-href="/cars?yard={{ $yard->id }}" tabindex="0" class="{{ $yard->is_active ? '' : 'text-ink-dim' }}">
                    <td class="grow">{{ $yard->name }}@unless ($yard->is_active) <span class="text-ink-dim">закрыта</span>@endunless</td>
                    <td class="hidden sm:table-cell"><x-ui.place>{{ trim(($yard->settlement?->name ?? '').', '.($yard->address ?? ''), ', ') }}</x-ui.place></td>
                    <td class="num nums">{{ $yard->capacity ?: '—' }}</td>
                    <td class="num nums">{{ $yard->stored_vehicles_count }}</td>
                    <td class="num nums {{ $free($yard) === 0 ? 'text-danger' : '' }}">{{ $free($yard) ?? '—' }}</td>
                </tr>
            @endforeach
        </x-ui.table>
    @elseif ($view === ListView::LIST)
        <div class="mt-6 flex flex-col gap-2">
            @foreach ($yards as $yard)
                <div data-controller="sheet" class="contents">
                    <button type="button" class="row w-full text-left" data-action="sheet#open">
                        <div class="min-w-0 flex-1">
                            <div class="font-medium">{{ $yard->name }}@unless ($yard->is_active) <span class="font-normal text-ink-dim">закрыта</span>@endunless</div>
                            <div class="row-sub"><x-ui.place class="tag">{{ trim(($yard->settlement?->name ?? '').', '.($yard->address ?? ''), ', ') }}</x-ui.place>@if ($yard->capacity)<span class="tag nums {{ $free($yard) === 0 ? 'text-danger' : '' }}">{{ $free($yard) }} свободно</span>@endif<span class="tag nums">{{ $yard->stored_vehicles_count }} ТС</span></div>
                        </div>
                        <x-ui.icon name="chevron-right" class="size-5 shrink-0 text-ink-dim"/>
                    </button>
                    <x-ui.sheet id="yard-{{ $yard->id }}" :title="$yard->name" wide>
                        @if ($yard->rows)<div class="mb-4"><x-park.yard-map :yard="$yard" :occupied="$yard->storedVehicles->whereNotNull('spot')->keyBy('spot')"/></div>@endif
                        @include('park.yards.form', ['yard' => $yard])
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
                        <button type="button" class="btn btn-s btn-quiet btn-round" data-action="sheet#open" aria-label="Изменить"><x-ui.icon name="edit" class="size-5"/></button>
                    </div>
                    @if ($yard->rows)<x-park.yard-map :yard="$yard" :occupied="$yard->storedVehicles->whereNotNull('spot')->keyBy('spot')"/>@endif
                    <x-ui.sheet id="yard-{{ $yard->id }}" title="Стоянка">@include('park.yards.form', ['yard' => $yard])</x-ui.sheet>
                </div>
            @endforeach
        </div>
    @endif
</x-ui.shell>
