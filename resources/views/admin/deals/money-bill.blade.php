{{-- Строки одного нашего счёта в «Продаже» (`x-deal.money`, кусок `admin.deals.money-bill`): документ (без суммы — она в шапке блока, `withSum` — у прочих
     счетов, у которых шапки нет), «Отправить менеджеру», ссылка на оплату, «Деньги пришли?» и заявки менеджера с ответами.
     Комиссия ПРАЙМ без документа (18.11.2026, «счёт на комиссию мы больше не формируем») — только ссылка и отметки; «оплачено» —
     словом шапки (`Billing\PayWord`), строкой не повторяется. --}}
@php use App\Support\Money; use App\Billing\InvoiceState; @endphp
@if (! $i->isDocument())
    @if ($i->state !== InvoiceState::Paid)
        @if (! $i->isShownToManager())<div class="send-under"><x-deal.issue-button :deal="$deal" :invoice="$i" block/></div>@endif
        @if ($link && $link->isOpen())
            <x-ui.share-link variant="row" :url="$link->url()" title="Оплата по ссылке" :text="$link->message()" label="Ссылка на оплату" :sub="$linkState" done="Ссылка на оплату в буфере"/>
        @endif
        @if ($paidBy->contains('id', $i->id))
            <x-deal.sale-row label="Оплатили наличными?" value="Отметить" tone="accent" data-controller="emit" data-action="emit#send" data-emit-event-param="paid-{{ $i->id }}:open"/>
        @endif
    @endif
@else
    {{-- Счёт — файлом, как документ (15.11.2026, владелец: «счёт должен быть не страницей, а кнопкой „Скачать PDF“»). --}}
    <x-deal.doc-row :href="$pdf ?? \App\Http\Admin\MoneyController::url($i)" :title="\App\Billing\PayOffer::invoiceName($i)" :date="\App\Support\Ago::day($i->issued_at)" name="schet.pdf" :download="(bool) $pdf">
        @if ($withSum && (! $payerRow || $i->party_id !== $payerId))<span>{{ $i->party?->title() }}</span>@endif
        @if ($withSum)<span class="nums whitespace-nowrap">{{ $i->isPartial() && $i->remaining() > 0 ? 'осталось '.Money::rub($i->remaining()).' из '.Money::rub($i->total) : Money::rub($i->total) }}</span>@endif
        @if ($i->shown_at)<span class="text-ink-muted">у менеджера с {{ \App\Support\Ago::day($i->shown_at) }}</span>@elseif ($withSum && ! $i->isShownToManager())<span class="text-ink-muted">не отправлен</span>@endif
    </x-deal.doc-row>
    {{-- Менеджер видит счёт только после «Отправить» (16.11.2026): сотрудник сперва проверяет. --}}
    @if (! $i->isShownToManager())<x-deal.send-row :deal="$deal" :action="'/work/deals/'.$deal->id.'/invoices/'.$i->id.'/show'" :label="\App\Billing\PayOffer::sendLabel($i)"/>@endif
    @if ($link && $link->isOpen())
        <x-ui.share-link variant="row" :url="$link->url()" title="Оплата по ссылке" :text="$link->message()" label="Ссылка на оплату" :sub="$linkState" done="Ссылка на оплату в буфере"/>
    @endif
    {{-- Отметка, что оплата ПРАЙМ / Р.О.С. принята, — под своим счётом (16.11.2026): та же шторка оплаты (`paid-{id}`). --}}
    @if ($paidBy->contains('id', $i->id))
        <x-deal.sale-row label="Деньги пришли?" value="Отметить" tone="accent" data-controller="emit" data-action="emit#send" data-emit-event-param="paid-{{ $i->id }}:open"/>
    @endif
@endif
@if (! $i->openLink() && ($i->isDocument() || $i->isShownToManager()))<x-billing.pay-status :invoice="$i" staff/>@endif
@foreach ($i->claims as $p)
    <x-money.payment :payment="$p" :slip="'/work/money/invoices/'.$i->id.'/payments/'.$p->id.'/slip'" staff>
        @unless ($inStep)
            <x-slot:acts>
                {{-- Три ответа одним рядом во всю ширину, чип платёжки — над ними (16.11.2026). --}}
                <div class="claim-acts">
                    <form method="post" action="/work/payments/{{ $p->id }}/confirm" class="contents" data-turbo-confirm="Поступила {{ Money::rub($p->amount) }}?">@csrf<x-ui.button size="sm">Поступила</x-ui.button></form>
                    <a href="{{ \App\Http\Admin\MoneyController::url($i) }}" class="btn btn-s btn-quiet" data-turbo-frame="_top">Не поступила</a>
                    <x-deal.claim-other :payment="$p"/>
                </div>
            </x-slot:acts>
        @endunless
    </x-money.payment>
@endforeach
