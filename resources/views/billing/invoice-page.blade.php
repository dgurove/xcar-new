{{-- Карточка счёта, одна на стоянку и CRM: строки, оплаты и заявленные оплаты слева, контрагент, ТС и сделка справа;
     плашка — «Оплачен» / «Перечислено». base — адрес счёта на этом хосте, claims — адрес действий по заявкам (только CRM). --}}
@php
    use App\Support\Money; use App\Billing\InvoiceState; use App\Billing\PaymentSource;
    $i = $invoice; $me = auth()->user();
    $offset = $i->payments->where('source', PaymentSource::Offset)->sum('amount');
@endphp
<x-ui.shell :title="($i->isOwed() ? ($i->isAgentFee() ? 'Вознаграждение ' : 'Мы должны ').$i->label() : 'Счёт '.$i->label())" :back="$back" cache="no-cache">
    <div class="-mt-3 mb-6 flex flex-wrap items-center gap-1.5" data-controller="sheet">
        <x-billing.light :invoice="$i"/>
        <span class="chip nums">{{ $i->issued_at->translatedFormat('j M Y') }}</span>
        <span class="chip">{{ $i->kind->label() }}</span>
        <span class="chip">{{ $i->vat ? 'с НДС' : 'без НДС' }}</span>
        @if ($i->external_no)<span class="chip nums">{{ $i->external_no }}</span>@endif
        @if ($i->sent_at)<span class="chip nums"><x-ui.icon name="send" class="size-3.5"/>{{ $i->sent_at->translatedFormat('j M') }}</span>@endif
        <button type="button" class="btn btn-s btn-quiet btn-round ml-auto" data-action="sheet#open" aria-label="Действия"><x-ui.icon name="more" class="size-5"/></button>
        <x-ui.sheet id="invoice-actions" :title="$i->label()" :open="$errors->has('due_at')">
            <div class="flex flex-col gap-2">
                @if ($mailUrl ?? null)<x-ui.button :href="$mailUrl" block><x-ui.icon name="send" class="size-4"/> {{ $i->sent_at ? 'Отправить снова' : 'Отправить' }}</x-ui.button>@endif
                @if ($file)<x-ui.button href="{{ $base }}/pdf" variant="secondary" block data-turbo="false" target="_blank"><x-ui.icon name="file" class="size-4"/> PDF</x-ui.button>@endif
                @if ($i->kind === \App\Billing\ChargeKind::Storage)<x-ui.button href="{{ $base }}/act" variant="ghost" block data-turbo="false" target="_blank">Акт хранения</x-ui.button>@endif
                @if ($i->number)<x-ui.button href="{{ $base }}/print" variant="ghost" block data-turbo="false" target="_blank">Печать</x-ui.button>@endif
                @if ($i->state === InvoiceState::Issued && $canManage)
                    <form method="post" action="{{ $base }}" class="mt-2 flex flex-col gap-3">
                        @csrf @method('put')
                        <div class="grid grid-cols-2 gap-3">
                            <x-ui.field name="due_at" :label="$i->isOwed() ? 'Перечислить до' : 'Оплатить до'" type="date" :value="$i->due_at->toDateString()" required/>
                            <x-ui.field name="external_no" :label="$i->isOwed() ? 'Чужой номер' : 'Чужой номер'" :value="$i->external_no"/>
                            <x-ui.field name="notes" label="Заметка в счёт" type="textarea" :value="$i->notes" span="col-span-2"/>
                        </div>
                        <x-ui.button variant="secondary" block>Сохранить</x-ui.button>
                    </form>
                @endif
            </div>
        </x-ui.sheet>
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
                    @if ($offset > 0)<div class="flex items-baseline gap-3 py-2 text-sm text-ink-muted"><span class="flex-1">Удержано агентское вознаграждение</span><span class="nums">{{ Money::rub($offset) }}</span></div>@endif
                </div>
            </x-ui.card>
            @if ($i->claims->isNotEmpty())
                {{-- Менеджер сообщил об оплате: платёжка есть, денег на счету ещё не видели. --}}
                <x-ui.card title="Сообщили об оплате" class="box-urgent">
                    <div class="flex flex-col gap-2">
                        @foreach ($i->claims as $p)
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="nums font-semibold">{{ Money::rub($p->amount) }}</span>
                                <span class="tag nums">{{ $p->paid_at->translatedFormat('j M Y') }}</span>
                                @if ($p->ref)<span class="tag nums">№ {{ $p->ref }}</span>@endif
                                @if ($p->slip())<a href="{{ $base }}/payments/{{ $p->id }}/slip" class="chip" data-turbo="false" target="_blank"><x-ui.icon name="file" class="size-3.5"/>платёжка</a>@endif
                                @if ($claims ?? null)
                                    <form method="post" action="{{ $claims }}/{{ $p->id }}/confirm" class="ml-auto contents" data-turbo-confirm="Поступило {{ Money::rub($p->amount) }}?">@csrf<x-ui.button size="sm">Поступило</x-ui.button></form>
                                    <div data-controller="sheet" class="contents">
                                        <x-ui.button type="button" size="sm" variant="ghost" data-action="sheet#open">Не поступила</x-ui.button>
                                        <x-ui.sheet id="reject-{{ $p->id }}" title="Оплата не поступила">
                                            <form method="post" action="{{ $claims }}/{{ $p->id }}/reject" class="flex flex-col gap-3">@csrf<x-ui.field name="reason" label="Что не так" placeholder="Денег на счёте нет, платёжка не читается"/><x-ui.button variant="secondary" block>Не поступила</x-ui.button></form>
                                        </x-ui.sheet>
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </x-ui.card>
            @endif
            <x-ui.card :title="$i->isOwed() ? 'Перечисления' : 'Оплаты'">
                @if ($i->payments->isEmpty())<p class="text-ink-muted">{{ $i->isOwed() ? 'Перечислений нет' : 'Оплат нет' }}</p>@endif
                <div class="flex flex-col divide-y divide-line/40">
                    @foreach ($i->payments as $p)
                        <div class="flex items-center gap-3 py-2">
                            <span class="min-w-0 flex-1">{{ $p->source->label() }}{{ $p->ref ? ' № '.$p->ref : '' }}{{ $p->note ? ' — '.$p->note : '' }}@if ($p->slip()) <a href="{{ $base }}/payments/{{ $p->id }}/slip" class="chip" data-turbo="false" target="_blank"><x-ui.icon name="file" class="size-3.5"/>платёжка</a>@endif</span>
                            <span class="nums text-sm text-ink-muted">{{ $p->paid_at->translatedFormat('j M Y') }}</span>
                            <span class="nums font-semibold">{{ Money::rub($p->amount) }}</span>
                            @if ($canManage)
                                <form method="post" action="{{ $base }}/payments/{{ $p->id }}" class="contents" data-turbo-confirm="Отменить {{ $i->isOwed() ? 'перечисление' : 'оплату' }} {{ Money::rub($p->amount) }}?">@csrf @method('delete')<button class="btn btn-ghost btn-s px-2 text-ink-muted" aria-label="Отменить"><x-ui.icon name="x" class="size-4"/></button></form>
                            @endif
                        </div>
                    @endforeach
                    @foreach ($i->allPayments->where('state', \App\Billing\PaymentState::Rejected) as $p)
                        <div class="flex items-center gap-3 py-2 text-ink-muted"><span class="min-w-0 flex-1">Не поступила{{ $p->reject_reason ? ': '.$p->reject_reason : '' }}</span><span class="nums text-sm">{{ $p->paid_at->translatedFormat('j M Y') }}</span><span class="nums line-through">{{ Money::rub($p->amount) }}</span></div>
                    @endforeach
                </div>
                @if ($i->remaining() > 0 && $i->state === InvoiceState::Issued)<div class="mt-3 nums">Остаток <b>{{ Money::rub($i->remaining()) }}</b></div>@endif
            </x-ui.card>
            @if ($i->notes)<x-ui.card title="Заметка"><p class="whitespace-pre-line">{{ $i->notes }}</p></x-ui.card>@endif
        </div>
        <div class="flex flex-col gap-4">
            <x-ui.card :title="$i->isOwed() ? 'Кому должны' : 'Плательщик'">
                <div class="font-medium"><x-vendor.name :party="$i->party"/></div>
                @if ($i->party->details())<div class="mt-1 text-sm text-ink-muted">{{ $i->party->details() }}</div>@endif
                @if ($i->party->bankDetails())<div class="mt-1 text-sm text-ink-muted">{{ $i->party->bankDetails() }}</div>@endif
                @if ($i->isOwed() && ! $i->party->payoutReady())<x-ui.pill tone="urgent" class="mt-2">Реквизитов для выплаты нет</x-ui.pill>@endif
                @if ($i->isOwed() && $i->party->payment_purpose)<div class="mt-2 text-sm">{{ $i->party->payment_purpose }}</div>@endif
                @if ($partiesUrl ?? null)<a href="{{ $partiesUrl }}" class="btn btn-ghost btn-s mt-2">Реквизиты</a>@endif
            </x-ui.card>
            @if ($i->vehicle)
                <x-ui.card title="ТС">
                    <a href="{{ \App\Support\Surface::Park->url('/cars/'.$i->vehicle_id) }}" class="font-medium" data-turbo="false">{{ $i->vehicle->titleWithYear() }}</a>
                    <div class="mt-1.5 flex flex-wrap gap-1.5">@if ($i->vehicle->ref)<span class="tag nums">{{ $i->vehicle->ref }}</span>@endif @if ($i->vehicle->yard)<x-ui.place class="tag">{{ $i->vehicle->yard->name }}</x-ui.place>@endif</div>
                </x-ui.card>
            @endif
            @if ($i->deal)
                <x-ui.card title="Сделка">
                    <a href="{{ $dealUrl }}" class="font-medium" @if (! str_starts_with($dealUrl, '/')) data-turbo="false" @endif>{{ $i->deal->offer?->titleWithYear() }}</a>
                    <div class="mt-1.5 flex flex-wrap gap-1.5"><span class="tag nums">№ {{ $i->deal->offer?->number }}</span>@if ($i->deal->buyer)<x-ui.person :user="$i->deal->buyer"/>@endif</div>
                </x-ui.card>
            @endif
            @if ($i->state === InvoiceState::Issued && $canManage && ! $i->payments()->where('source', '!=', PaymentSource::Offset)->exists())
                <form method="post" action="{{ $base }}/void" data-turbo-confirm="Аннулировать {{ $i->isOwed() ? 'обязательство' : 'счёт' }} {{ $i->label() }}?">@csrf<x-ui.button variant="ghost" block>Аннулировать</x-ui.button></form>
            @endif
        </div>
    </div>
    @if ($i->state === InvoiceState::Issued && $canManage)
        <div data-controller="sheet">
            <x-ui.action-bar><x-ui.button type="button" class="min-w-0 flex-1" data-action="sheet#open">{{ $i->isOwed() ? 'Перечислено' : 'Оплачен' }}</x-ui.button></x-ui.action-bar>
            <x-ui.sheet id="pay" :title="$i->isOwed() ? 'Перечисление' : 'Оплата'" :open="$errors->has('amount')"><x-billing.pay-form :invoice="$i" :action="$base.'/payments'" :sources="$sources"/></x-ui.sheet>
        </div>
    @endif
</x-ui.shell>
