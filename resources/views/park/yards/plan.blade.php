{{-- Схема парковки во весь экран (`x-park.yard-plan`, `yard_plan_controller`): где стоит каждая ТС и перестановка нажатием —
     машину, потом место. Выбранная — плашкой внизу холста (ссылка в дело): значок типа, название, госномер, место. Под схемой лоток «без
     места»: ТС на этой парковке без места или с местом не из схемы. Правка схемы — круглая кнопка у заголовка
     (`editsYardPlan`). --}}
@php
    $all = $cars->values()->concat($loose);
    // Плашке выбранной — данные, не разметка: значок типа и плашку номера собирает `yard_plan_controller` (раньше — Blade::render
    // на каждую машину при каждой загрузке и живом обновлении).
    $info = $all->mapWithKeys(fn ($v) => [$v->id => [
        'title' => $v->titleWithYear(), 'short' => $v->brand?->name ?? $v->ref ?? 'ТС',
        'spot' => $v->spot !== null && ($cars[$v->spot] ?? null)?->id === $v->id ? $v->spot : null,
        'cat' => $v->category?->value, 'plate' => $v->plate,
    ]]);
    $back = auth()->user()->seesYards() ? ['Парковки', '/yards'] : ['Наличие', '/cars?yard='.$yard->id];
@endphp
<x-ui.shell :title="$yard->name" :back="$back">
    @if ($edits && $yard->rows)
        <x-slot:actions class="!w-auto ml-auto">
            <a href="/yards/{{ $yard->id }}/plan/edit" class="btn btn-s btn-quiet btn-round" aria-label="Изменить схему" title="Изменить схему"><x-ui.icon name="edit" class="size-4"/></a>
        </x-slot:actions>
    @endif
    @if (! $yard->rows)
        <x-ui.empty class="mt-6" :href="$edits ? '/yards/'.$yard->id.'/plan/edit' : null" link="Нарисовать схему">Схемы нет</x-ui.empty>
    @else
        <div class="plan-page" data-controller="yard-plan" data-yard-plan-yard-value="{{ $yard->id }}" data-yard-plan-cars-value="{{ json_encode($info) }}" data-yard-plan-sprite-value="{{ \App\Support\Icons::url() }}">
            <label class="toolbar-search plan-find"><x-ui.icon name="search" class="size-4 shrink-0 text-ink-muted"/><input type="search" placeholder="Госномер, VIN, убыток" autocomplete="off" enterkeyhint="search" aria-label="Найти ТС" data-yard-plan-target="find" data-action="input->yard-plan#find"></label>
            <div class="plan-stage" data-yard-plan-target="stage">
                <x-park.yard-plan :yard="$yard" :cars="$cars"/>
                @include('park.yards.plan-zoom', ['controller' => 'yard-plan'])
                <div class="plan-bar" data-yard-plan-target="bar" hidden>
                    {{-- Плашка — ссылка в дело ТС целиком, справа ›; × — снять выбор. --}}
                    <a href="#" class="plan-bar-link" data-yard-plan-target="barLink">
                        <span class="plan-bar-icon" data-yard-plan-target="barIcon"></span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate font-medium" data-yard-plan-target="barTitle"></span>
                            <span class="plan-bar-facts" data-yard-plan-target="barFacts"></span>
                        </span>
                        <x-ui.icon name="chevron-right" class="size-4 shrink-0 text-ink-dim"/>
                    </a>
                    <button type="button" class="btn btn-s btn-quiet btn-round shrink-0" data-action="yard-plan#deselect" aria-label="Отменить"><x-ui.icon name="x" class="size-4"/></button>
                </div>
            </div>
            @if ($loose->isNotEmpty())
                <div class="plan-tray">
                    <div class="plan-tray-title">Без места <span class="nums text-ink-dim">{{ $loose->count() }}</span></div>
                    <div class="plan-tray-cars">
                        @foreach ($loose as $v)
                            <button type="button" class="plan-chip" data-yard-plan-target="tray" data-vehicle="{{ $v->id }}" data-find="{{ mb_strtolower(implode(' ', array_filter([$v->plate, $v->ref, $v->vin, $v->brand?->name]))) }}" data-action="yard-plan#take">
                                <x-ui.cat-icon :category="$v->category"/><span class="truncate">{{ $v->brand?->name ?? $v->ref ?? 'ТС' }}</span>@if ($v->plate)<x-ui.plate :value="$v->plate"/>@endif
                            </button>
                        @endforeach
                    </div>
                </div>
            @endif
            <form method="post" class="hidden" data-yard-plan-target="move">@csrf<input type="hidden" name="yard_id" value="{{ $yard->id }}"><input type="hidden" name="spot"></form>
            <form method="post" class="hidden" data-yard-plan-target="swap">@csrf<input type="hidden" name="with"></form>
        </div>
    @endif
</x-ui.shell>
