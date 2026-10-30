{{-- Долг XCar логисту: реквизиты строкой и счета «Ждут выплаты» / «Выплачено» — один кусок на «Оплаты» админа (по логисту)
     и пилюлю «XCar» самого логиста. --}}
@php use App\Support\Money; $party = $row->party; $u = $row->user; @endphp
<div class="list">
    <div class="contents" data-controller="sheet">
        <x-ui.sheet-row>
            <x-ui.row-icon name="bank" size="s" :tone="$party->exists && $party->payoutReady() ? 'plain' : 'urgent'"/>
            <span class="min-w-0 flex-1">
                <span class="block">Реквизиты</span>
                <span class="row-sub">
                    @if ($party->exists && $party->filled())
                        <span>{{ $party->kind->label() }}@if ($party->bankDetails()), {{ $party->bankDetails() }}@endif</span>
                    @else
                        <span class="text-urgent">не указаны, выплатить не сможем</span>
                    @endif
                </span>
            </span>
            <x-ui.chevron/>
        </x-ui.sheet-row>
        <x-ui.sheet id="party-{{ $u->id }}" title="Реквизиты" wide tall>
            <form method="post" action="/money/{{ $u->id }}/party" class="flex flex-col gap-4">
                @csrf @method('put')
                <x-billing.party-fields :party="$party->exists ? $party : null" :staff="false"/>
                <x-ui.sheet-foot>Сохранить</x-ui.sheet-foot>
            </form>
        </x-ui.sheet>
    </div>
</div>

@foreach (['waiting' => 'Ждут выплаты', 'paid' => 'Выплачено'] as $key => $head)
    @if ($row->{$key}->isNotEmpty())
        <div class="list-head mt-6">{{ $head }} <span class="nums">{{ $row->{$key}->count() }}, {{ Money::rub((int) $row->{$key}->sum('total')) }}</span></div>
        <div class="list">
            @foreach ($row->{$key} as $invoice)
                {{-- Задача знает только последний счёт: прежний оплаченный (цену поменяли после части выплаты) — по строке счёта. --}}
                @php
                    $t = $row->tasks->get($invoice->id);
                    $line = $t ? null : trim((string) preg_replace('/^Вывоз,\s*/u', '', (string) $invoice->charges->first()?->title));
                @endphp
                <a @if ($t) href="/transports?preset=all&peek={{ $t->id }}" @endif class="row">
                    <span class="min-w-0 flex-1">
                        <span class="block truncate">{{ $t ? $t->title() : ($line ?: 'Вывоз') }}</span>
                        @if ($t)<span class="row-sub">{{ implode(', ', array_filter([$t->city(), $t->distance_km ? $t->distance_km.' км' : null])) }}</span>@endif
                    </span>
                    <span class="shrink-0 text-right">
                        <span class="block nums">{{ Money::rub((int) $invoice->total) }}</span>
                        @if ($key === 'paid')
                            <span class="block text-sm text-ink-muted">выплачено{{ $invoice->paid_at ? ' '.\App\Support\Ago::day($invoice->paid_at) : '' }}</span>
                        @elseif ($invoice->isOverdue())
                            <span class="block text-sm text-danger">{{ $invoice->overdueLabel() }}</span>
                        @elseif ($invoice->due_at)
                            <span class="block text-sm text-ink-muted">до {{ \App\Support\Ago::html($invoice->due_at, words: false) }}</span>
                        @endif
                    </span>
                </a>
            @endforeach
        </div>
    @endif
@endforeach
@if ($row->waiting->isEmpty() && $row->paid->isEmpty())
    <x-ui.empty class="mt-6">Вывозов с ценой пока нет</x-ui.empty>
@endif
