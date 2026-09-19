{{-- Площадки: занятость числом и полосой, занятые места чипами по рядам (чип ведёт к ТС), форма — город, адрес, ряды. --}}
<x-ui.shell title="Стоянки">
    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        @foreach ($yards as $yard)
            @php
                $free = $yard->capacity ? max(0, $yard->capacity - $yard->stored_vehicles_count) : null;
                $share = $yard->capacity ? min(100, round($yard->stored_vehicles_count / $yard->capacity * 100)) : 0;
                $occupied = $yard->storedVehicles->whereNotNull('spot')->keyBy('spot');
            @endphp
            <div class="box flex flex-col gap-3" data-controller="sheet">
                <div class="flex items-start gap-2">
                    <div class="min-w-0 flex-1">
                        <div class="font-medium">{{ $yard->name }}@unless ($yard->is_active) <x-ui.pill tone="closed" class="!min-h-0 !py-1 text-xs">закрыта</x-ui.pill>@endunless</div>
                        @if ($yard->settlement || $yard->address)<div class="text-sm text-ink-muted"><x-ui.place>{{ trim(($yard->settlement?->name ?? '').', '.($yard->address ?? ''), ', ') }}</x-ui.place></div>@endif
                    </div>
                    <button type="button" class="btn btn-s btn-quiet btn-round" data-action="sheet#open" aria-label="Изменить"><x-ui.icon name="edit" class="size-5"/></button>
                </div>
                <a href="/cars?yard={{ $yard->id }}" class="flex flex-col gap-1.5">
                    <div class="flex items-baseline justify-between"><span class="nums text-[40px] leading-none">{{ $yard->stored_vehicles_count }}</span><span class="flex gap-1.5">@if ($yard->capacity)<span class="tag nums">из {{ $yard->capacity }}</span><span class="tag nums">свободно {{ $free }}</span>@else<span class="tag">ТС</span>@endif</span></div>
                    @if ($yard->capacity)<div class="h-1.5 overflow-hidden rounded-full bg-surface-3"><div class="h-full {{ $share > 90 ? 'bg-danger' : 'bg-accent' }}" style="width:{{ $share }}%"></div></div>@endif
                </a>
                @if ($yard->rows)
                    <div class="flex flex-col gap-1.5">
                        @foreach ($yard->rows as $row)
                            @php $name = mb_strtoupper(trim((string) ($row['name'] ?? ''))); @endphp
                            <div class="flex flex-wrap items-center gap-1">
                                @if ($name !== '')<span class="w-6 text-xs text-ink-dim">{{ $name }}</span>@endif
                                @for ($i = 1; $i <= (int) ($row['n'] ?? 0); $i++)
                                    @php $spot = $name === '' ? (string) $i : $name.'-'.$i; $v = $occupied[$spot] ?? null; $days = $v?->accepted_at ? (int) $v->accepted_at->diffInDays(now()) : null; $tone = \App\Park\Idle::tone($days); @endphp
                                    @if ($v)<a href="/cars?yard={{ $yard->id }}&vid=table&peek={{ $v->id }}" class="chip nums !px-1.5 !py-0 text-xs {{ $tone === 'danger' ? 'bg-danger-soft text-danger' : ($tone === 'urgent' ? 'bg-urgent-soft text-urgent' : 'bg-accent-soft text-accent-text') }}" title="{{ $v->ref }}">{{ $i }}</a>
                                    @else<span class="chip nums !px-1.5 !py-0 text-xs text-ink-dim">{{ $i }}</span>@endif
                                @endfor
                            </div>
                        @endforeach
                    </div>
                @endif
                <x-ui.sheet id="yard-{{ $yard->id }}" title="Стоянка">
                    <form method="post" action="/yards/{{ $yard->id }}" class="flex flex-col gap-4">
                        @csrf @method('put')
                        <x-ui.field name="name" label="Название" :value="$yard->name" required/>
                        <x-ui.field name="settlement_id" label="Город" :options="$settlements" placeholder="—" :value="$yard->settlement_id"/>
                        <x-ui.field name="address" label="Адрес" :value="$yard->address"/>
                        <x-ui.field name="capacity" label="Мест" inputmode="numeric" :value="$yard->capacity"/>
                        <x-ui.field name="rows" label="Ряды: буква и число мест, по строке" type="textarea" :value="implode(PHP_EOL, array_map(fn ($r) => trim(($r['name'] ?? '').': '.($r['n'] ?? 0), ': '), $yard->rows ?? []))" placeholder="A: 20"/>
                        <x-ui.field name="notes" label="Заметки" type="textarea" :value="$yard->notes"/>
                        <x-ui.check name="is_active" :checked="$yard->is_active">Работает</x-ui.check>
                        <x-ui.button block>Сохранить</x-ui.button>
                    </form>
                </x-ui.sheet>
            </div>
        @endforeach
        <div class="box flex flex-col justify-center" data-controller="sheet">
            <x-ui.button type="button" variant="secondary" data-action="sheet#open"><x-ui.icon name="plus" class="size-5"/> Стоянка</x-ui.button>
            <x-ui.sheet id="yard-new" title="Новая стоянка">
                <form method="post" action="/yards" class="flex flex-col gap-4">
                    @csrf
                    <x-ui.field name="name" label="Название" required autofocus/>
                    <x-ui.field name="settlement_id" label="Город" :options="$settlements" placeholder="—"/>
                    <x-ui.field name="address" label="Адрес"/>
                    <x-ui.field name="capacity" label="Мест" inputmode="numeric"/>
                    <x-ui.field name="rows" label="Ряды: буква и число мест, по строке" type="textarea" placeholder="A: 20"/>
                    <x-ui.button block>Добавить</x-ui.button>
                </form>
            </x-ui.sheet>
        </div>
    </div>
</x-ui.shell>
