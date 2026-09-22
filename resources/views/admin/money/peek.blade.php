{{-- Окошко счёта или вознаграждения по сделке: светофор, документ, менеджер, ТС; остаток справа; заявки менеджера с
     «Поступило / Не поступила», выплата и оплата формами, PDF и «Аннулировать» чипами; строки и оплаты. --}}
@php use App\Support\Money; use App\Billing\InvoiceState; use App\Billing\PaymentSource; $i = $invoice; $href = '/work/money/invoices/'.$i->id; $offer = $i->deal?->offer; @endphp
<turbo-frame id="peek" target="_top">
    <x-ui.peek :href="$href" :title="$i->isOwed() ? 'Вознаграждение '.$i->party->name : 'Счёт '.$i->label().', '.$i->party->name" :photo="$offer?->mainPhoto()">
        <x-slot:marks>
            <span class="tag nums font-semibold">{{ Money::rub($i->remaining() > 0 ? $i->remaining() : $i->total) }}{{ $i->isPartial() ? ' из '.Money::rub($i->total) : '' }}</span>
            <x-billing.light :invoice="$i"/>
            <span class="tag nums">{{ $i->isOwed() ? 'обязательство' : $i->label() }} от {{ $i->issued_at->translatedFormat('j M') }}</span>
            <span class="tag">{{ $i->kind->label() }}</span>
            @if ($i->deal?->buyer)<x-ui.person :user="$i->deal->buyer"/>@endif
            @if ($offer)<a href="/work/deals/{{ $i->deal_id }}" class="tag">{{ $offer->titleWithYear() }}</a>@endif
            @if ($i->isOwed() && ! $i->party->payoutReady())<x-ui.pill tone="urgent" class="!min-h-0 !py-1 text-xs">Реквизитов нет</x-ui.pill>@endif
        </x-slot:marks>
        <x-slot:actions>
            @foreach ($i->claims as $p)
                <div class="flex w-full flex-wrap items-center gap-2">
                    <span class="text-sm">Сообщил об оплате</span><span class="nums font-semibold">{{ Money::rub($p->amount) }}</span><span class="tag nums">{{ $p->paid_at->translatedFormat('j M') }}</span>
                    @if ($p->ref)<span class="tag nums">№ {{ $p->ref }}</span>@endif
                    @if ($p->slip())<a href="{{ $href }}/payments/{{ $p->id }}/slip" class="chip" data-turbo="false" target="_blank"><x-ui.icon name="file" class="size-3.5"/>платёжка</a>@endif
                    <form method="post" action="/work/payments/{{ $p->id }}/confirm" class="contents" data-turbo-confirm="Поступило {{ Money::rub($p->amount) }}?">@csrf<button class="btn btn-s btn-accent">Поступило</button></form>
                    <details class="w-full"><summary class="btn btn-s btn-ghost inline-flex cursor-pointer">Не поступила</summary>
                        <form method="post" action="/work/payments/{{ $p->id }}/reject" class="mt-2 flex gap-2">@csrf<input name="reason" class="field-input min-w-0 flex-1" placeholder="Что не так"><x-ui.button size="sm" variant="secondary">Отметить</x-ui.button></form>
                    </details>
                </div>
            @endforeach
            @if ($i->state === InvoiceState::Issued)
                <details class="w-full"><summary class="btn btn-s btn-accent inline-flex cursor-pointer">{{ $i->isOwed() ? 'Выплатить' : 'Оплачен' }}</summary><div class="mt-3"><x-billing.pay-form :invoice="$i" :action="$href.'/payments'" :sources="$sources"/></div></details>
            @endif
            @if ($i->number)<a href="{{ $href }}/pdf" class="chip" data-turbo="false" target="_blank">PDF</a>@endif
            @if ($i->state === InvoiceState::Issued && ! $i->payments()->where('source', '!=', PaymentSource::Offset)->exists())
                <form method="post" action="{{ $href }}/void" class="contents" data-turbo-confirm="Аннулировать {{ $i->isOwed() ? 'обязательство' : 'счёт' }}?">@csrf<button class="chip text-ink-muted">Аннулировать</button></form>
            @endif
        </x-slot:actions>
        <div class="mt-4 flex flex-col divide-y divide-line/40 text-sm">
            @foreach ($i->charges as $c)
                <div class="flex items-baseline gap-2 py-1.5"><span class="min-w-0 flex-1">{{ $c->title }}</span><span class="nums shrink-0">{{ Money::rub($c->amount) }}</span></div>
            @endforeach
            @foreach ($i->payments as $p)
                <div class="flex items-baseline gap-2 py-1.5 text-accent-text"><span class="min-w-0 flex-1">{{ $p->source->label() }}{{ $p->ref ? ' № '.$p->ref : '' }}{{ $p->note ? ' — '.$p->note : '' }}</span><span class="nums text-ink-muted">{{ $p->paid_at->translatedFormat('j M') }}</span><span class="nums shrink-0">− {{ Money::rub($p->amount) }}</span></div>
            @endforeach
        </div>
        <x-slot:row><x-money.table-row :invoice="$i"/></x-slot:row>
    </x-ui.peek>
</turbo-frame>
