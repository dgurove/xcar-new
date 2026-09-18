{{-- Окошко счёта: светофор, номер и дата, контрагент, ТС; остаток справа; «Оплачен» формой, «Аннулировать» и документы чипами; строки и оплаты. --}}
@php use App\Support\Money; use App\Billing\InvoiceState; $i = $invoice; $href = '/money/invoices/'.$i->id; @endphp
<turbo-frame id="peek" target="_top">
    <x-ui.peek :href="$href" :title="$i->party->name" :photo="$i->vehicle?->mainPhoto()">
        <x-slot:marks>
            <x-billing.light :invoice="$i"/>
            <span class="tag nums">{{ $i->isOwed() ? 'мы должны' : $i->label() }} от {{ $i->issued_at->translatedFormat('j M') }}</span>
            <span class="tag">{{ $i->kind->label() }}</span>
            @if ($i->vehicle)<a href="/cars/{{ $i->vehicle_id }}" class="tag">{{ $i->vehicle->titleWithYear() }}</a>@endif
        </x-slot:marks>
        <x-slot:aside>
            <div class="nums text-lg font-semibold">{{ Money::rub($i->remaining() > 0 ? $i->remaining() : $i->total) }}</div>
            @if ($i->isPartial())<div class="nums text-xs text-ink-muted">из {{ Money::rub($i->total) }}</div>@endif
        </x-slot:aside>
        <x-slot:actions>
            @if ($i->state === InvoiceState::Issued && auth()->user()->canManagePark())
                <details class="w-full"><summary class="btn btn-s btn-accent inline-flex cursor-pointer">{{ $i->isOwed() ? 'Перечислено' : 'Оплачен' }}</summary><div class="mt-3">@include('park.money.pay-form')</div></details>
            @endif
            @if ($i->number)<a href="{{ $href }}/pdf" class="chip" data-turbo="false" target="_blank">PDF</a>@endif
            @if ($i->kind === \App\Billing\ChargeKind::Storage)<a href="{{ $href }}/act" class="chip" data-turbo="false" target="_blank">Акт хранения</a>@endif
            @if ($i->state === InvoiceState::Issued && $i->paid == 0 && auth()->user()->canManagePark())
                <form method="post" action="{{ $href }}/void" class="contents" data-turbo-confirm="Аннулировать счёт?">@csrf<button class="chip text-ink-muted">Аннулировать</button></form>
            @endif
        </x-slot:actions>
        <div class="mt-4 flex flex-col divide-y divide-line/40 text-sm">
            @foreach ($i->charges as $c)
                <div class="flex items-baseline gap-2 py-1.5"><span class="min-w-0 flex-1">{{ $c->title }}</span><span class="nums text-ink-muted">{{ rtrim(rtrim(number_format($c->qty, 2, '.', ''), '0'), '.') }} {{ $c->unitLabel() }} × {{ Money::nums($c->price) }}</span><span class="nums shrink-0">{{ Money::rub($c->amount) }}</span></div>
            @endforeach
            @foreach ($i->payments as $p)
                <div class="flex items-baseline gap-2 py-1.5 text-accent-text"><span class="min-w-0 flex-1">{{ $p->source->label() }}{{ $p->ref ? ' № '.$p->ref : '' }}</span><span class="nums text-ink-muted">{{ $p->paid_at->translatedFormat('j M') }}</span><span class="nums shrink-0">− {{ Money::rub($p->amount) }}</span></div>
            @endforeach
        </div>
        <x-slot:row><x-billing.table-row :invoice="$i"/></x-slot:row>
    </x-ui.peek>
</turbo-frame>
