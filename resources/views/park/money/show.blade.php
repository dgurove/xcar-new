{{-- Карточка счёта: строки и оплаты слева, контрагент, ТС, сделка справа; плашка — «Оплачен». --}}
@php use App\Support\Money; use App\Billing\InvoiceState; use App\Support\Surface; $i = $invoice; $me = auth()->user(); @endphp
<x-ui.shell :title="($i->isOwed() ? 'Мы должны ' : 'Счёт ').$i->label()" :back="['Деньги', '/money']" cache="no-cache">
    <div class="-mt-3 mb-6 flex flex-wrap items-center gap-1.5" data-controller="sheet">
        <x-billing.light :invoice="$i"/>
        <span class="chip nums">{{ $i->issued_at->translatedFormat('j M Y') }}</span>
        <span class="chip">{{ $i->kind->label() }}</span>
        <span class="chip">{{ $i->vat ? 'с НДС' : 'без НДС' }}</span>
        @if ($i->external_no)<span class="chip nums">{{ $i->external_no }}</span>@endif
        @if ($file)<a href="/money/invoices/{{ $i->id }}/pdf" class="chip" data-turbo="false" target="_blank"><x-ui.icon name="file" class="size-3.5"/>PDF</a>@endif
        @if ($i->kind === \App\Billing\ChargeKind::Storage)<a href="/money/invoices/{{ $i->id }}/act" class="chip" data-turbo="false" target="_blank">Акт хранения</a>@endif
        @if ($i->number)<a href="/money/invoices/{{ $i->id }}/print" class="chip" data-turbo="false" target="_blank">Печать</a>@endif
        @if ($i->sent_at)<span class="chip nums"><x-ui.icon name="send" class="size-3.5"/>{{ $i->sent_at->translatedFormat('j M') }}</span>@endif
        @if (!$i->isOwed() && $i->state === InvoiceState::Issued && $i->vehicle && $me->canManagePark())<a href="/mail/new?invoice={{ $i->id }}" class="chip bg-accent-soft text-accent-text"><x-ui.icon name="send" class="size-3.5"/>{{ $i->sent_at ? 'Отправить снова' : 'Отправить' }}</a>@endif
        @if ($i->state === InvoiceState::Issued && $me->canManagePark())
            <button type="button" class="chip" data-action="sheet#open"><x-ui.icon name="edit" class="size-3.5"/>Изменить</button>
            <x-ui.sheet id="invoice-edit" title="Счёт" :open="$errors->has('due_at')">
                <form method="post" action="/money/invoices/{{ $i->id }}" class="flex flex-col gap-3">
                    @csrf @method('put')
                    <div class="grid grid-cols-2 gap-3">
                        <x-ui.field name="due_at" :label="$i->isOwed() ? 'Перечислить до' : 'Оплатить до'" type="date" :value="$i->due_at->toDateString()" required/>
                        <x-ui.field name="external_no" :label="$i->isOwed() ? '№ у вендора' : 'Чужой номер'" :value="$i->external_no"/>
                        <x-ui.field name="notes" label="Заметка в счёт" type="textarea" :value="$i->notes" span="col-span-2"/>
                    </div>
                    <x-ui.button block>Сохранить</x-ui.button>
                </form>
            </x-ui.sheet>
        @endif
    </div>
    <div class="grid grid-cols-1 items-start gap-4 lg:grid-cols-[minmax(0,1fr)_22rem]">
        <div class="flex flex-col gap-4">
            <x-ui.card title="Строки">
                <div class="flex flex-col divide-y divide-line/40">
                    @foreach ($i->charges as $c)
                        <div class="flex items-baseline gap-3 py-2"><span class="min-w-0 flex-1">{{ $c->title }}</span><span class="nums text-sm text-ink-muted">{{ rtrim(rtrim(number_format($c->qty, 2, '.', ''), '0'), '.') }} {{ $c->unitLabel() }} × {{ Money::nums($c->price) }}</span><span class="nums shrink-0 font-semibold">{{ Money::rub($c->amount) }}</span></div>
                    @endforeach
                    <div class="flex items-baseline gap-3 py-2"><span class="flex-1">Итого</span><span class="nums text-lg font-semibold">{{ Money::rub($i->total) }}</span></div>
                    @if ($i->vat)<div class="text-sm text-ink-muted">в том числе НДС {{ Money::rub($i->vatAmount()) }}</div>@endif
                </div>
            </x-ui.card>
            <x-ui.card title="Оплаты">
                @if ($i->payments->isEmpty())<p class="text-ink-muted">Оплат нет</p>@endif
                <div class="flex flex-col divide-y divide-line/40">
                    @foreach ($i->payments as $p)
                        <div class="flex items-center gap-3 py-2">
                            <span class="min-w-0 flex-1">{{ $p->source->label() }}{{ $p->ref ? ' № '.$p->ref : '' }}{{ $p->note ? ' — '.$p->note : '' }}@if ($p->slip()) <a href="/money/invoices/{{ $i->id }}/payments/{{ $p->id }}/slip" class="chip" data-turbo="false" target="_blank"><x-ui.icon name="file" class="size-3.5"/>платёжка</a>@endif</span>
                            <span class="nums text-sm text-ink-muted">{{ $p->paid_at->translatedFormat('j M Y') }}</span>
                            <span class="nums font-semibold">{{ Money::rub($p->amount) }}</span>
                            @if ($me->canManagePark())<form method="post" action="/money/invoices/{{ $i->id }}/payments/{{ $p->id }}" data-turbo-confirm="Отменить оплату?">@csrf @method('delete')<button class="btn btn-ghost btn-s px-2 text-ink-muted" aria-label="Отменить"><x-ui.icon name="x" class="size-4"/></button></form>@endif
                        </div>
                    @endforeach
                </div>
                @if ($i->remaining() > 0 && $i->state === InvoiceState::Issued)<div class="mt-3 nums">Остаток <b>{{ Money::rub($i->remaining()) }}</b></div>@endif
            </x-ui.card>
            @if ($i->notes)<x-ui.card title="Заметка"><p class="whitespace-pre-line">{{ $i->notes }}</p></x-ui.card>@endif
        </div>
        <div class="flex flex-col gap-4">
            <x-ui.card :title="$i->isOwed() ? 'Кому должны' : 'Плательщик'">
                <div class="font-medium">{{ $i->party->name }}</div>
                @if ($i->party->details())<div class="mt-1 text-sm text-ink-muted">{{ $i->party->details() }}</div>@endif
                @if ($i->party->bankDetails())<div class="mt-1 text-sm text-ink-muted">{{ $i->party->bankDetails() }}</div>@endif
                @if ($i->isOwed() && $i->party->payment_purpose)<div class="mt-2 text-sm">{{ $i->party->payment_purpose }}</div>@endif
                <a href="/money/parties" class="btn btn-ghost btn-s mt-2">Реквизиты</a>
            </x-ui.card>
            @if ($i->vehicle)
                <x-ui.card title="ТС">
                    <a href="/cars/{{ $i->vehicle_id }}" class="font-medium">{{ $i->vehicle->titleWithYear() }}</a>
                    <div class="mt-1.5 flex flex-wrap gap-1.5">@if ($i->vehicle->ref)<span class="tag nums">{{ $i->vehicle->ref }}</span>@endif @if ($i->vehicle->yard)<x-ui.place class="tag">{{ $i->vehicle->yard->name }}</x-ui.place>@endif</div>
                </x-ui.card>
            @endif
            @if ($i->deal)
                <x-ui.card title="Сделка"><a href="{{ Surface::Crm->url('/work/deals/'.$i->deal_id) }}" class="font-medium" data-turbo="false">Сделка по № {{ $i->deal->offer?->number }}</a></x-ui.card>
            @endif
            @if ($i->state === InvoiceState::Issued && $i->paid == 0 && $me->canManagePark())
                <form method="post" action="/money/invoices/{{ $i->id }}/void" data-turbo-confirm="Аннулировать счёт?">@csrf<x-ui.button variant="ghost" block>Аннулировать</x-ui.button></form>
            @endif
        </div>
    </div>
    @if ($i->state === InvoiceState::Issued && $me->canManagePark())
        <div data-controller="sheet">
            <x-ui.action-bar><x-ui.button type="button" class="min-w-0 flex-1" data-action="sheet#open">{{ $i->isOwed() ? 'Перечислено' : 'Оплачен' }}</x-ui.button></x-ui.action-bar>
            <x-ui.sheet id="pay" :title="$i->isOwed() ? 'Перечисление' : 'Оплата'" :open="$errors->has('amount')">@include('park.money.pay-form')</x-ui.sheet>
        </div>
    @endif
</x-ui.shell>
