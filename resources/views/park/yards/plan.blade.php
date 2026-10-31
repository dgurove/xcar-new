{{-- Схема парковки во весь экран (`x-park.yard-plan`, `yard_plan_controller`): где стоит каждая ТС и перестановка нажатием —
     машину, потом место. Под схемой лоток «без места»: ТС на этой парковке, у которых места нет или оно не из схемы.
     Схему правят администратор и управляющие (`editsYardPlan`) — кнопка «Изменить» ведёт в редактор. --}}
@php
    $all = $cars->values()->concat($loose);
    $info = $all->mapWithKeys(fn ($v) => [$v->id => [
        'title' => $v->titleWithYear(), 'short' => $v->brand?->name ?? $v->ref ?? 'ТС', 'plate' => $v->plate, 'spot' => $cars->has($v->spot ?? '') && $cars[$v->spot]->id === $v->id ? $v->spot : null,
        'days' => ($d = $v->daysStored()) ? $d.' д' : null,
    ]]);
    $back = auth()->user()->seesYards() ? ['Парковки', '/yards'] : ['Наличие', '/cars?yard='.$yard->id];
@endphp
<x-ui.shell :title="$yard->name" :back="$back" class="plan-shell">
    @if (! $yard->rows)
        <x-ui.empty class="mt-6" :href="$edits ? '/yards/'.$yard->id.'/plan/edit' : null" link="Нарисовать схему">Схемы нет</x-ui.empty>
    @else
        <div class="plan-page{{ $loose->isNotEmpty() ? ' has-tray' : '' }}" data-controller="yard-plan" data-yard-plan-yard-value="{{ $yard->id }}" data-yard-plan-cars-value="{{ json_encode($info) }}">
            <div class="plan-tools">
                <label class="toolbar-search plan-find"><x-ui.icon name="search" class="size-4 shrink-0 text-ink-muted"/><input type="search" placeholder="Госномер, VIN, убыток" autocomplete="off" enterkeyhint="search" aria-label="Найти ТС" data-yard-plan-target="find" data-action="input->yard-plan#find"></label>
                @if ($edits)<a href="/yards/{{ $yard->id }}/plan/edit" class="btn btn-s btn-quiet shrink-0 rounded-full"><x-ui.icon name="edit" class="size-4"/>Изменить</a>@endif
            </div>
            <div class="plan-stage">
                <x-park.yard-plan :yard="$yard" :cars="$cars"/>
                <div class="plan-zoom">
                    <button type="button" class="plan-zoom-btn" data-action="yard-plan#zoomIn" aria-label="Ближе"><x-ui.icon name="plus" class="size-5"/></button>
                    <button type="button" class="plan-zoom-btn" data-action="yard-plan#zoomOut" aria-label="Дальше"><span class="plan-minus"></span></button>
                    <button type="button" class="plan-zoom-btn" data-action="yard-plan#fit" aria-label="Вся парковка"><x-ui.icon name="expand" class="size-4"/></button>
                </div>
                <div class="plan-bar" data-yard-plan-target="bar" hidden>
                    <div class="min-w-0 flex-1">
                        <div class="truncate font-medium" data-yard-plan-target="barTitle"></div>
                        <div class="truncate text-sm text-ink-muted" data-yard-plan-target="barFacts"></div>
                    </div>
                    <a href="#" class="btn btn-s btn-quiet shrink-0 rounded-full" data-yard-plan-target="barLink">Открыть</a>
                    <button type="button" class="btn btn-s btn-quiet btn-round shrink-0" data-action="yard-plan#deselect" aria-label="Отменить"><x-ui.icon name="x" class="size-4"/></button>
                </div>
            </div>
            @if ($loose->isNotEmpty())
                <div class="plan-tray">
                    <div class="plan-tray-title">Без места <span class="nums text-ink-dim">{{ $loose->count() }}</span></div>
                    <div class="plan-tray-cars">
                        @foreach ($loose as $v)
                            <button type="button" class="plan-chip" data-yard-plan-target="tray" data-vehicle="{{ $v->id }}" data-find="{{ mb_strtolower(implode(' ', array_filter([$v->plate, $v->ref, $v->vin, $v->brand?->name]))) }}" data-action="yard-plan#take">
                                <span class="truncate">{{ $v->brand?->name ?? $v->ref ?? 'ТС' }}</span>@if ($v->plate)<x-ui.plate :value="$v->plate"/>@endif
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
