{{-- Карточка счёта, одна на стоянку и CRM: строки, оплаты и заявленные оплаты слева, контрагент, ТС и сделка справа;
     плашка — «Оплачен» / «Выплачено» (менеджеру) / «Перечислено» (вендору). base — адрес счёта на этом хосте, claims — адрес действий по заявкам (только CRM).
     07.10.2026: поля строками (.fields), кнопка .sheet-foot. --}}
@php
    use App\Support\Money; use App\Billing\InvoiceState; use App\Billing\PaymentSource;
    $i = $invoice; $me = auth()->user();
    // Вознаграждение менеджеру — «выплата», долг стоянки вендору — «перечисление».
    $fee = $i->isAgentFee();
    $offset = $i->payments->where('source', PaymentSource::Offset)->sum('amount');
@endphp
{{-- Выплата без номера — по имени получателя: label() отдал бы вид («Агентское вознаграждение»). --}}
<x-ui.shell :title="($i->isOwed() ? ($i->isAgentFee() ? 'Выплата менеджеру '.$i->party->name : 'Перечисление вендору '.$i->label()) : 'Счёт '.$i->label())" :back="$back" cache="no-cache">
    <x-slot:badge><x-billing.light :invoice="$i" class="title-state"/></x-slot:badge>
    <div class="-mt-3 mb-6 flex items-start gap-3" data-controller="sheet">
        <div class="flex min-w-0 flex-1 flex-wrap items-center gap-1.5">
        {{-- Состояние — у заголовка; фразой — что за счёт и когда (владелец 10.10.2026: «что-то чипами, что-то текстом»);
             чипом только чужой номер — его сверяют и копируют. --}}
        @if ($i->external_no)<span class="tag nums gap-1">№<x-ui.copy-code :value="$i->external_no"/></span>@endif
        <div class="detail-line basis-full !mt-0.5 text-sm text-ink-muted">
            <span>{{ $i->kind->label() }}</span>
            <span class="nums">от {{ \App\Support\Ago::html($i->issued_at, words: false) }}</span>
            <span>{{ $i->vatRate() ? 'НДС '.$i->vatRate().' %' : 'без НДС' }}</span>
            @if ($i->sent_at)<span class="nums">отправлен {{ \App\Support\Ago::html($i->sent_at, words: false) }}</span>@endif
        </div>
        </div>
        <button type="button" class="more-pill more-pill--light shrink-0" data-action="sheet#open" aria-label="Действия"><x-ui.icon name="more" class="size-[22px]"/></button>
        <x-ui.sheet id="invoice-actions" :title="$i->label()">
            <div class="flex flex-col gap-2">
                {{-- Письмо — окном писем (28.10.2026, одна дверь): шторка «Действия» закрывается, окно открывается. --}}
                @if ($mailUrl ?? null)<button type="button" class="btn btn-accent btn-block" data-controller="emit" data-action="emit#send sheet#close" data-emit-event-param="letters:open" data-emit-url-param="{{ $mailUrl }}"><x-ui.icon name="send" class="size-4"/> {{ $i->sent_at ? 'Отправить снова' : 'Отправить' }}</button>@endif
                {{-- Документы — в шторке документов поверх окна; акт хранения — PDF, если выставлен закрытием месяца, иначе страница. --}}
                @if ($file)<x-ui.doc :doc="['url' => $base.'/pdf', 'type' => 'pdf', 'name' => 'Счет '.$i->fileNo().'.pdf', 'label' => 'Счёт '.$i->label()]" class="btn btn-quiet btn-block"><x-ui.icon name="file" class="size-4"/> PDF</x-ui.doc>@endif
                {{-- Акт: у реестра — прежний файл страховой (Excel или Word, у РГС ещё приложение), у счёта на ТС — PDF или страница. --}}
                @include('billing.act-docs', ['invoice' => $i, 'base' => $base, 'class' => 'btn btn-ghost btn-block'])
                @if ($i->isRegistry() && $i->park && $i->kind === \App\Billing\ChargeKind::Storage)
                    <x-ui.doc :doc="['url' => $base.'/acts', 'type' => 'html', 'name' => 'Акты хранения по ТС']" class="btn btn-ghost btn-block"/>
                @endif
                @if ($i->number)<x-ui.doc :doc="['url' => $base.'/print', 'type' => 'html', 'name' => 'Печатная форма']" class="btn btn-ghost btn-block">Печать</x-ui.doc>@endif
                @if ($i->state === InvoiceState::Issued && $canManage)
                    <form method="post" action="{{ $base }}" class="mt-2 flex flex-col gap-3">
                        @csrf @method('put')
                        <div class="fields">
                            <x-ui.field name="due_at" :label="$i->isOwed() ? ($fee ? 'Выплатить до' : 'Перечислить до') : 'Оплатить до'" type="date" :value="$i->due_at?->toDateString()" required/>
                            <x-ui.field name="external_no" :label="$i->isOwed() ? 'Чужой номер' : 'Чужой номер'" :value="$i->external_no"/>
                            <x-ui.field name="notes" label="Заметка в счёт" type="textarea" :value="$i->notes"/>
                        </div>
                        <x-ui.sheet-foot variant="secondary">Сохранить</x-ui.sheet-foot>
                    </form>
                @endif
            </div>
        </x-ui.sheet>
    </div>
    {{-- Данные отправленного документа изменились (`Closing::drift`): исправленный — тем же номером и «ред.», прежний аннулируется. --}}
    @if ($drift ?? null)
        <div class="box mb-4 flex flex-wrap items-center gap-3">
            <div class="min-w-0 flex-1">
                <div class="text-danger">Изменилось после отправки</div>
                <div class="text-sm text-ink-muted">было <span class="nums">{{ Money::rub($drift['was']) }}</span>, сейчас <span class="nums">{{ Money::rub($drift['now']) }}</span></div>
            </div>
            @if ($reviseUrl ?? null)<a href="{{ $reviseUrl }}" class="btn btn-s btn-accent">Исправленный</a>@endif
        </div>
    @endif
    <div class="grid grid-cols-1 items-start gap-4 lg:grid-cols-[minmax(0,1fr)_22rem]">
        <div class="flex flex-col gap-4">
            {{-- Файлы документа месяца строками: просмотр в шторке документов, «Скачать» (05.11.2026, «Счета и акты»). --}}
            @if ($files ?? null)
                <div class="list" data-docs-scope>@foreach ($files as $f)<x-ui.file :name="$f['name']" :href="$f['url']" class="row" download/>@endforeach</div>
            @endif
            @if ($i->isRegistry())
                {{-- Реестр за месяц — составом по смыслу, а не списком всех машин (`ClosingDoc::composition`); все ТС — шторкой. --}}
                <div><x-billing.registry-summary :composition="\App\Billing\ClosingDoc::composition($i->charges->loadMissing('vehicle'), \Illuminate\Support\Carbon::instance($i->month))" :month="$i->month" :total="(float) $i->total" :id="'i'.$i->id"/></div>
            @else
            <x-ui.card :title="'Строки'">
                <div class="flex flex-col divide-y divide-line/40">
                    @foreach ($i->charges as $c)
                        <div class="flex items-baseline gap-3 py-2"><span class="min-w-0 flex-1">{{ $c->title }}</span><span class="nums text-sm text-ink-muted">{{ \App\Support\Qty::format($c->qty) }} {{ $c->unitLabel() }} × {{ Money::nums($c->price) }}</span><span class="nums shrink-0 font-semibold">{{ Money::rub($c->amount) }}</span></div>
                    @endforeach
                    @if ($i->vat_on_top)<div class="flex items-baseline gap-3 py-2"><span class="flex-1">{{ $i->vatLabel() }}</span><span class="nums shrink-0 font-semibold">{{ Money::rub($i->vatAmount()) }}</span></div>@endif
                    <div class="flex items-baseline gap-3 py-2"><span class="flex-1">Итого</span><span class="nums text-lg font-semibold">{{ Money::rub($i->total) }}</span></div>
                    @if ($i->vatRate() && ! $i->vat_on_top)<div class="text-sm text-ink-muted">{{ mb_strtolower($i->vatLabel()) }} {{ Money::rub($i->vatAmount()) }}</div>@endif
                    @if ($offset > 0)<div class="flex items-baseline gap-3 py-2 text-sm text-ink-muted"><span class="flex-1">Менеджер оставил себе вознаграждение</span><span class="nums">{{ Money::rub($offset) }}</span></div>@endif
                </div>
            </x-ui.card>
            @endif
            @if ($i->claims->isNotEmpty())
                {{-- Менеджер сообщил об оплате: платёжка есть, денег на счету ещё не видели. --}}
                <x-ui.card title="Сообщили об оплате" class="box-urgent">
                    <div class="flex flex-col gap-2">
                        @foreach ($i->claims as $p)
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="nums font-semibold">{{ Money::rub($p->amount) }}</span>
                                <span class="tag nums">{{ $p->paid_at->format('d.m.Y') }}</span>
                                @if ($p->ref)<span class="tag nums">№ {{ $p->ref }}</span>@endif
                                @if ($slip = $p->slip())<x-ui.doc :doc="['url' => $base.'/payments/'.$p->id.'/slip', 'type' => \App\Support\Docs::type($slip->mime_type, $slip->file_name), 'name' => $slip->file_name, 'label' => 'Платёжка']" class="chip"><x-ui.icon name="file" class="size-3.5"/>платёжка</x-ui.doc>@endif
                                @if ($claims ?? null)
                                    <form method="post" action="{{ $claims }}/{{ $p->id }}/confirm" class="ml-auto contents" data-turbo-confirm="Поступило {{ Money::rub($p->amount) }}?">@csrf<x-ui.button size="sm">Поступило</x-ui.button></form>
                                    <div data-controller="sheet" class="contents">
                                        <x-ui.button type="button" size="sm" variant="ghost" data-action="sheet#open">Не поступила</x-ui.button>
                                        <x-ui.sheet id="reject-{{ $p->id }}" title="Оплата не поступила">
                                            <form method="post" action="{{ $claims }}/{{ $p->id }}/reject" class="flex flex-col gap-3">@csrf<x-ui.field name="reason" label="Что не так" placeholder="Денег на счёте нет, платёжка не читается"/><x-ui.sheet-foot variant="secondary">Не поступила</x-ui.sheet-foot></form>
                                        </x-ui.sheet>
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </x-ui.card>
            @endif
            @if (($claims ?? null) && ! $i->isOwed() && ($i->openLink() || (\App\Billing\Acquiring\PayLink::eligible($i) && $i->remaining() - $i->claimed() > 0)))
                {{-- CRM: ссылка на оплату счёта — адрес, «Отправить», что делал плательщик (`x-billing.pay-status`). --}}
                <div class="list"><x-billing.pay-status :invoice="$i" staff/></div>
            @endif
            <x-ui.card :title="$i->isOwed() ? ($fee ? 'Выплаты' : 'Перечисления') : 'Оплаты'">
                @if ($i->payments->isEmpty())<p class="text-ink-muted">{{ $i->isOwed() ? ($fee ? 'Выплат нет' : 'Перечислений нет') : 'Оплат нет' }}</p>@endif
                <div class="flex flex-col divide-y divide-line/40">
                    @foreach ($i->payments as $p)
                        <div class="flex items-center gap-3 py-2">
                            <span class="min-w-0 flex-1">{{ $p->source->label() }}{{ $p->ref ? ' № '.$p->ref : '' }}{{ $p->note ? ', '.$p->note : '' }}@if ($slip = $p->slip()) <x-ui.doc :doc="['url' => $base.'/payments/'.$p->id.'/slip', 'type' => \App\Support\Docs::type($slip->mime_type, $slip->file_name), 'name' => $slip->file_name, 'label' => 'Платёжка']" class="chip"><x-ui.icon name="file" class="size-3.5"/>платёжка</x-ui.doc>@endif</span>
                            <span class="nums text-sm text-ink-muted">{{ $p->paid_at->format('d.m.Y') }}</span>
                            <span class="nums font-semibold">{{ Money::rub($p->amount) }}</span>
                            @if ($canManage)
                                <form method="post" action="{{ $base }}/payments/{{ $p->id }}" class="contents" data-turbo-confirm="Отменить {{ $i->isOwed() ? 'перечисление' : 'оплату' }} {{ Money::rub($p->amount) }}?">@csrf @method('delete')<button class="btn btn-ghost btn-s px-2 text-ink-muted" aria-label="Отменить"><x-ui.icon name="x" class="size-4"/></button></form>
                            @endif
                        </div>
                    @endforeach
                    @foreach ($i->allPayments->where('state', \App\Billing\PaymentState::Rejected) as $p)
                        <div class="flex items-center gap-3 py-2 text-ink-muted"><span class="min-w-0 flex-1">Не поступила{{ $p->reject_reason ? ': '.$p->reject_reason : '' }}</span><span class="nums text-sm">{{ $p->paid_at->format('d.m.Y') }}</span><span class="nums line-through">{{ Money::rub($p->amount) }}</span></div>
                    @endforeach
                </div>
                @if ($i->remaining() > 0 && $i->state === InvoiceState::Issued)<div class="mt-3 nums">Остаток <b>{{ Money::rub($i->remaining()) }}</b></div>@endif
            </x-ui.card>
            @if ($i->notes)<x-ui.card title="Заметка"><p class="whitespace-pre-line">{{ $i->notes }}</p></x-ui.card>@endif
        </div>
        <div class="flex flex-col gap-4">
            <x-ui.card :title="$i->isOwed() ? 'Кому выплатить' : 'Плательщик'">
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
            @if ($i->state === InvoiceState::Issued && $canManage && ! $i->payments()->whereNotIn('source', PaymentSource::soft())->exists())
                <form method="post" action="{{ $base }}/void" data-turbo-confirm="Аннулировать {{ $i->isOwed() ? ($fee ? 'выплату' : 'перечисление') : 'счёт' }} {{ $i->label() }}?">@csrf<x-ui.button variant="ghost" block>Аннулировать</x-ui.button></form>
            @endif
        </div>
    </div>
    @if ($i->state === InvoiceState::Issued && $canManage)
        <div data-controller="sheet">
            <x-ui.action-bar><x-ui.button type="button" class="min-w-0 flex-1" data-action="sheet#open">{{ $i->isOwed() ? ($fee ? 'Выплачено' : 'Перечислено') : 'Оплачен' }}</x-ui.button></x-ui.action-bar>
            <x-ui.sheet id="pay" :title="$i->isOwed() ? ($fee ? 'Выплата' : 'Перечисление') : 'Оплата'"><x-billing.pay-form :invoice="$i" :action="$base.'/payments'" :sources="$sources" sheet/></x-ui.sheet>
        </div>
    @endif
    @if ($mailUrl ?? null)<x-mail.window/>@endif
</x-ui.shell>
