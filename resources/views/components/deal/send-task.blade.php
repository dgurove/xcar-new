{{-- Счёт или договор сделки в шаге оплаты хода сделки (16.11.2026, владелец: «в ходе сделки тоже кнопки „Отправить договор“ и
     „Отправить счёт“, но только в этапе оплаты — а то сотрудники могут не заметить, что они не отправлены, и удивляться, почему
     не платят»). Не отправлен — рыжая строка дела «Менеджер не видит …», нажатие — шторка: документ (проверить PDF) и «Отправить
     менеджеру» (`x-deal.send-row`, тот же маршрут, что в «Продаже»). Отправлен — тихая строка с галкой и временем.
     `invoice` — счёт сделки; без него — договор. --}}
@props(['deal', 'invoice' => null])
@php
    if ($invoice) {
        $sent = $invoice->isShownToManager();
        $at = $invoice->shown_at ?? $invoice->paid_at;
        $name = 'счёт '.$invoice->label();
        $media = $invoice->hasPdf();
        $href = $media ? '/work/money/invoices/'.$invoice->id.'/pdf' : '/work/money/invoices/'.$invoice->id;
        $action = '/work/deals/'.$deal->id.'/invoices/'.$invoice->id.'/show';
        $sub = \App\Support\Money::rub($invoice->total);
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
@if ($sent)
    <x-route.task-row icon="check" static>
        {{ \Illuminate\Support\Str::ucfirst($name) }} у менеджера
        @if ($at)<x-slot:sub>{!! \App\Support\Ago::html($at, true, words: false) !!}</x-slot:sub>@endif
    </x-route.task-row>
@else
    <div class="contents" data-controller="sheet">
        <x-route.task-row icon="file" urgent :aria-controls="$sheet">
            Менеджер не видит {{ $name }}
            @if ($sub)<x-slot:sub>{{ $sub }}</x-slot:sub>@endif
        </x-route.task-row>
        <x-ui.sheet :id="$sheet" :title="$invoice ? 'Счёт '.$invoice->label() : 'Договор'">
            <div class="list">
                @if ($invoice)
                    <x-deal.doc-row :href="$href" :title="'Счёт '.$invoice->label()" :date="\App\Support\Ago::day($invoice->issued_at)" name="schet.pdf" :download="$media">
                        <span>{{ $invoice->party?->title() }}</span><span class="nums whitespace-nowrap">{{ \App\Support\Money::rub($invoice->total) }}</span>
                    </x-deal.doc-row>
                @else
                    <x-deal.doc-row :href="$href" :title="'Договор купли-продажи'" name="dkp.pdf" :file="$doc->fileName()">
                        <span>{{ $contract->buyer?->party?->name }}</span>@if ($contract->price)<span class="nums whitespace-nowrap">{{ \App\Support\Money::rub($contract->price) }}</span>@endif
                    </x-deal.doc-row>
                @endif
                <x-deal.send-row :deal="$deal" :action="$action"/>
            </div>
        </x-ui.sheet>
    </div>
@endif
