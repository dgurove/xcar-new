{{-- Счёт или договор сделки в шаге оплаты хода сделки (16.11.2026, владелец: «в ходе сделки тоже кнопки „Отправить договор“ и
     „Отправить счёт“, но только в этапе оплаты — а то сотрудники могут не заметить, что они не отправлены, и удивляться, почему
     не платят»). Не отправлен — рыжая строка дела «Менеджер не видит …», нажатие — шторка: документ (проверить PDF) и «Отправить
     менеджеру» (`x-deal.send-row`, тот же маршрут, что в «Продаже»). Отправлен — тихая строка с галкой и временем.
     `invoice` — счёт сделки; без него — договор. Счёт со ссылкой — «Ссылка на оплату подбора N ₽»; счёта за подбор нет (18.11.2026,
     владелец: «только либо по ссылке либо наличкой») — в шторке «К оплате» и сумма; запрошенный счёт — строкой-документом. --}}
@props(['deal', 'invoice' => null])
@php
    if ($invoice) {
        $sent = $invoice->isShownToManager();
        $at = $invoice->shown_at ?? $invoice->paid_at;
        $linked = \App\Billing\PayOffer::linked($invoice);
        $name = mb_lcfirst(\App\Billing\PayOffer::name($invoice));
        $missing = $linked ? 'Менеджер не получил ссылку на оплату '.\App\Billing\PayOffer::what($invoice) : 'Менеджер не видит '.$name;
        $media = $invoice->hasPdf();
        $href = $media ? '/work/money/invoices/'.$invoice->id.'/pdf' : \App\Http\Admin\MoneyController::url($invoice);
        $action = '/work/deals/'.$deal->id.'/invoices/'.$invoice->id.'/show';
        $sub = \App\Support\Money::rub(\App\Billing\PayOffer::amount($invoice));
        $sheet = 'send-invoice-'.$invoice->id;
    } else {
        $contract = \App\Offers\DealContract::for($deal);
        $doc = new \App\Offers\DkpDocument($contract);
        $sent = $contract->shown_at !== null;
        $at = $contract->shown_at;
        $name = 'договор'.($contract->code ? ' № '.$contract->code : '');
        $href = '/work/deals/'.$deal->id.'/dkp.pdf';
        $action = '/work/deals/'.$deal->id.'/contract/show';
        $sub = null;
        $sheet = 'send-contract-'.$deal->id;
    }
@endphp
@php
    // Отправленная комиссия (18.11.2026, владелец: «скопировать ссылку нигде нельзя, нажать, что наличными оплачено»): живая ссылка —
    // строкой со «Скопировать» / «Отправить», под ней «Оплатили наличными…» — шторка оплаты с наличными.
    $openLink = $invoice && $sent && $invoice->state === \App\Billing\InvoiceState::Issued ? $invoice->openLink() : null;
    $cash = $invoice && $sent && ! $invoice->isDocument() && $invoice->state === \App\Billing\InvoiceState::Issued && $invoice->remaining() > 0 && auth()->user()?->canManageCrm();
@endphp
@if ($sent && ($openLink || $cash))
    <div class="list mt-2">
        @if ($openLink)
            @php [$linkState] = \App\Http\Admin\MoneyLinks::short($openLink); @endphp
            <x-ui.share-link variant="row" :url="$openLink->url()" :title="$openLink->title()" :text="$openLink->message()" label="Ссылка на оплату" :sub="$linkState.', '.\App\Support\Money::rub($openLink->amount)" done="Ссылка на оплату в буфере"/>
        @endif
        @if ($cash)
            <div class="contents" data-controller="sheet">
                <x-deal.sale-row label="Оплатили наличными?" value="Отметить" tone="accent" data-action="sheet#open"/>
                <x-ui.sheet :id="'cash-'.$invoice->id" :title="$invoice->isDocument() ? 'Оплата по счёту '.$invoice->label() : 'Оплата подбора'">
                    <x-billing.pay-form :invoice="$invoice" :action="'/work/money/invoices/'.$invoice->id.'/payments'" :source="$invoice->isDocument() ? null : 'cash'" sheet/>
                </x-ui.sheet>
            </div>
        @endif
    </div>
@elseif ($sent)
    <x-route.task-row icon="check" static>
        {{ \Illuminate\Support\Str::ucfirst($name) }} у менеджера
        @if ($at)<x-slot:sub>{!! \App\Support\Ago::html($at, true, words: false) !!}</x-slot:sub>@endif
    </x-route.task-row>
@else
    <div class="contents" data-controller="sheet">
        <x-route.task-row icon="file" urgent :aria-controls="$sheet">
            {{ $missing ?? 'Менеджер не видит '.$name }}
            @if ($sub)<x-slot:sub>{{ $sub }}</x-slot:sub>@endif
        </x-route.task-row>
        <x-ui.sheet :id="$sheet" :title="$invoice ? \App\Billing\PayOffer::name($invoice) : 'Договор'">
            <div class="list">
                @if ($invoice && $linked)
                    <div class="row justify-between gap-3">
                        <span class="min-w-0">К оплате</span>
                        <span class="nums whitespace-nowrap">{{ $sub }}</span>
                    </div>
                    @if ($invoice->isDocument())
                    <x-deal.doc-row :href="$href" :title="\App\Billing\PayOffer::invoiceName($invoice)" :date="\App\Support\Ago::day($invoice->issued_at)" name="schet.pdf" :download="$media">
                        <span>или оплатить по счёту</span>
                    </x-deal.doc-row>
                    @endif
                @elseif ($invoice && ! $invoice->isDocument())
                    <div class="row justify-between gap-3">
                        <span class="min-w-0">К оплате</span>
                        <span class="nums whitespace-nowrap">{{ $sub }}</span>
                    </div>
                @elseif ($invoice)
                    <x-deal.doc-row :href="$href" :title="\App\Billing\PayOffer::invoiceName($invoice)" :date="\App\Support\Ago::day($invoice->issued_at)" name="schet.pdf" :download="$media">
                        <span>{{ $invoice->party?->title() }}</span><span class="nums whitespace-nowrap">{{ \App\Support\Money::rub($invoice->total) }}</span>
                    </x-deal.doc-row>
                @else
                    <x-deal.doc-row :href="$href" :title="'Договор купли-продажи'" name="dkp.pdf" :file="$doc->fileName()">
                        <span>{{ $contract->buyer?->party?->name }}</span>@if ($contract->price)<span class="nums whitespace-nowrap">{{ \App\Support\Money::rub($contract->price) }}</span>@endif
                    </x-deal.doc-row>
                @endif
                @if ($invoice && ! $invoice->isDocument())
                    <div class="send-under"><x-deal.issue-button :deal="$deal" :invoice="$invoice" block/></div>
                @else
                    <x-deal.send-row :deal="$deal" :action="$action" :label="$invoice ? \App\Billing\PayOffer::sendLabel($invoice) : 'Отправить менеджеру'"/>
                @endif
            </div>
        </x-ui.sheet>
    </div>
@endif
