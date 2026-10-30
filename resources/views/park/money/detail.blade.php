{{-- Карточка счёта: светофор, номер и дата, контрагент, ТС; остаток справа; «Оплачен» формой, «Аннулировать» и документы чипами; строки и оплаты. --}}
@php use App\Support\Money; use App\Billing\InvoiceState; $i = $invoice; $href = '/money/invoices/'.$i->id; @endphp
<x-ui.detail>
    <x-ui.row-card :href="$href" :title="$i->party->name" :photo="$i->vehicle?->mainPhoto()">
        {{-- Состояние — у названия; фразой — какой счёт, от какого дня, за что и по какой ТС (ТС — ссылкой в дело). --}}
        <x-slot:badge><x-billing.light :invoice="$i" class="title-state"/></x-slot:badge>
        <x-slot:line>
            <span class="nums">{{ $i->isOwed() ? 'мы должны' : $i->label() }} от {{ \App\Support\Ago::html($i->issued_at, words: false) }}</span>
            <span>{{ mb_strtolower($i->kind->label()) }}</span>
            @if ($i->vehicle)<a href="/cars/{{ $i->vehicle_id }}" class="text-accent-text">{{ $i->vehicle->titleWithYear() }}</a>@elseif ($i->isRegistry())<span>{{ $i->what() }}</span>@endif
        </x-slot:line>
        <x-slot:aside>
            <div class="nums text-lg font-semibold">{{ Money::rub($i->remaining() > 0 ? $i->remaining() : $i->total) }}</div>
            @if ($i->isPartial())<div class="nums text-xs text-ink-muted">из {{ Money::rub($i->total) }}</div>@endif
        </x-slot:aside>
        <x-slot:actions>
            @if ($i->state === InvoiceState::Issued && auth()->user()->canManagePark())
                <details class="w-full"><summary class="btn btn-s btn-accent inline-flex cursor-pointer">{{ $i->isOwed() ? 'Перечислено' : 'Оплачен' }}</summary><div class="mt-3">@include('park.money.pay-form')</div></details>
            @endif
            @if ($i->number)<x-ui.doc :doc="['url' => $href.'/pdf', 'type' => 'pdf', 'name' => 'schet-'.$i->number.'.pdf', 'label' => 'Счёт '.$i->label()]" class="chip">PDF</x-ui.doc>@endif
            {{-- Акт хранения: PDF, если выставлен закрытием месяца, иначе страница на печать. --}}
            @if ($i->kind === \App\Billing\ChargeKind::Storage)<x-ui.doc :doc="['url' => $href.'/act', 'type' => $i->hasMedia('act') ? 'pdf' : 'html', 'name' => 'Акт хранения']" class="chip"/>@endif
            @if ($i->state === InvoiceState::Issued && $i->paid == 0 && auth()->user()->canManagePark())
                <form method="post" action="{{ $href }}/void" class="contents" data-turbo-confirm="Аннулировать счёт?">@csrf<button class="chip text-ink-muted">Аннулировать</button></form>
            @endif
        </x-slot:actions>
        <div class="mt-4 flex flex-col divide-y divide-line/40 text-sm">
            {{-- Реестр — машинами: сумма строк каждой и ссылка в дело; строки хранения по датам — в акте. --}}
            @if ($i->isRegistry())
                @foreach ($i->registryVehicles() as $v)
                    <a href="/cars/{{ $v->id }}" class="flex items-baseline gap-2 py-1.5"><span class="min-w-0 flex-1 truncate">{{ $v->titleWithYear() }}@if ($v->ref) <span class="text-ink-muted">{{ $v->ref }}</span>@endif</span><span class="nums shrink-0">{{ Money::rub($i->charges->where('vehicle_id', $v->id)->sum('amount')) }}</span></a>
                @endforeach
            @endif
            @foreach ($i->isRegistry() ? [] : $i->charges as $c)
                <div class="flex items-baseline gap-2 py-1.5"><span class="min-w-0 flex-1">{{ $c->title }}</span><span class="nums text-ink-muted">{{ \App\Support\Qty::format($c->qty) }} {{ $c->unitLabel() }} × {{ Money::nums($c->price) }}</span><span class="nums shrink-0">{{ Money::rub($c->amount) }}</span></div>
            @endforeach
            @foreach ($i->payments as $p)
                <div class="flex items-baseline gap-2 py-1.5 text-accent-text"><span class="min-w-0 flex-1">{{ $p->source->label() }}{{ $p->ref ? ' № '.$p->ref : '' }}</span><span class="nums text-ink-muted">{{ \App\Support\Ago::html($p->paid_at, words: false) }}</span><span class="nums shrink-0">− {{ Money::rub($p->amount) }}</span></div>
            @endforeach
        </div>
    </x-ui.row-card>
</x-ui.detail>
