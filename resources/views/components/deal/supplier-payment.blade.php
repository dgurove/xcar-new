{{-- «Оплата поставщику» — всё, что уходит за машину, своим блоком рядом с «Продажей» (15.11.2026, владелец: «один блок „Продажа“,
     второй „Оплата поставщику“»; «Деньги» и «ДКП ПРАЙМ» были непонятны). Кому, сколько, оплачено ли и, пока не оплачено, назначение
     платежа полем для копирования (оно своё у каждой сделки). Реквизитов нет (16.11.2026, владелец: «в банке получателя выбираем —
     всё предзаполняется»; посмотреть — карточка вендора).
     - ПРАЙМ / Р.О.С. (платим мы): получатель и сумма из «Договора комиссии» (`deal_contracts.supplier_*`, `FillCommissionOwner`),
       иначе поставщик и `Deal::supplierCost`; не сходится с нашей суммой — красным; оплачено — `Deal::supplierPaid`
       (`supplier_paid_at`). Р.О.С. переводит сам — словом под получателем.
     - «Страховой напрямую»: платит покупатель, реквизитов нет — счёт страховой файлом (`SupplierInvoice::shown`), состояние как у
       менеджера (платёжка у нас, подтвердил поставщик).
     - ДКП собственника: «Оплата собственнику» — кому по ДКП и взаимозачёт.
     - Гараж «платит менеджер»: кто платит словом. --}}
@props(['deal'])
@php
    use App\Offers\DealScheme;
    use App\Support\Money;
    $offer = $deal->offer;
    // Кто платит поставщику или собственнику — покупатель по договору (16.11.2026, вариант Б): свой, не плательщик счёта ПРАЙМ.
    $cb = $deal->contract?->buyer;
    $buyerWho = match (true) {
        ! $cb => 'покупатель',
        $cb->id === $deal->buyer_id => 'менеджер '.$cb->name,
        default => $cb->name,
    };
    $scheme = $deal->schemeOf();
    $contract = $deal->contract;
    $paid = $deal->supplierPaid();
    $paidState = $paid ? ['оплачено'.($deal->supplier_paid_at ? ' '.\App\Support\Ago::day($deal->supplier_paid_at) : ''), 'text-open'] : ['не оплачено', 'text-ink-muted'];
    $kind = match (true) {
        $deal->isGarageManager() => 'manager',
        $scheme === DealScheme::Insurer => 'insurer',
        $scheme === DealScheme::OwnerDkp => 'owner',
        $scheme->paysCar() || $deal->isCommission() => 'we',
        default => null,
    };
    if ($kind === 'we') {
        $price = $contract?->supplier_price;
        $ours = $deal->supplierCost();
        $amount = $price ?? $ours;
        $diff = $price !== null && $ours !== null ? $ours - $price : 0;
        $to = $contract?->supplier_requisites['name'] ?? $offer->vendor?->name ?? 'Поставщик';
        $basis = $contract?->commissioned() && $contract->commissionNo() ? 'договор комиссии № '.$contract->commissionNo() : null;
    }
    if ($kind === 'insurer') {
        // Платёж страховой у Альфы (оплата до вывоза) подтверждает поставщик: пока ждём — что платёжка у нас.
        $insurerState = match (true) {
            $paid => ['оплачено', 'text-open'],
            \App\Workflow\Requirement::where('deal_id', $deal->id)->whereNotNull('done_at')->whereIn('answer->exit', \App\Offers\Documents\PaymentProofPdf::EXITS)->exists() => ['ждём подтверждения поставщика', 'text-ink-muted'],
            default => ['ждём платёжку', 'text-ink-muted'],
        };
        $bill = \App\Offers\SupplierInvoice::shown($offer)->first();
    }
    $sum = fn ($v) => $v === null ? 'уточним' : Money::rub((int) $v);
@endphp
@if ($kind)
    <x-ui.card :title="$kind === 'owner' ? 'Оплата владельцу' : 'Оплата поставщику'" {{ $attributes->class('sale-card') }}>
        @if ($kind === 'we' || $kind === 'insurer')
            <x-slot:actions>
                @php [$word, $tone] = $kind === 'we' ? $paidState : $insurerState; @endphp
                <span class="text-sm {{ $tone }}">{{ $word }}</span>
            </x-slot:actions>
        @endif
        <div class="flex flex-col gap-3">
            @if ($kind === 'we')
                <div>
                    <span class="block break-words">{{ $to }}</span>
                    @if ($scheme === DealScheme::Ros)<span class="block text-sm text-ink-muted">переводит Р.О.С.</span>@elseif ($basis && ($paid || ! $contract?->supplier_purpose))<span class="block text-sm text-ink-muted">{{ $basis }}</span>@endif
                    @if ($amount !== null)
                        <x-ui.copy-text class="nums mt-1 block w-fit pay-sum {{ $diff !== 0 ? 'text-danger' : '' }}" :value="(string) $amount" done="Сумма в буфере">{{ Money::rub($amount) }}</x-ui.copy-text>
                        @if ($diff !== 0)<span class="block text-sm text-danger">у нас {{ Money::rub($ours) }}, разница {{ Money::rub(abs($diff)) }}</span>@endif
                        @if ($deal->supplier_cost !== null && $deal->cost !== null && (int) $deal->cost !== (int) $amount)<span class="block text-sm text-ink-muted">у менеджера закупочная {{ Money::rub($deal->cost) }}</span>@endif
                    @endif
                </div>
                @if (! $paid && $contract?->supplier_purpose)<x-ui.copy-field label="Назначение платежа" done="Назначение в буфере">{{ $contract->supplier_purpose }}</x-ui.copy-field>@endif
            @elseif ($kind === 'insurer')
                <div>
                    <span class="block">{{ $offer->vendor?->name ?? 'Страховая' }}</span>
                    <span class="block text-sm text-ink-muted">платит {{ $buyerWho }} напрямую</span>
                    <span class="nums mt-1 block pay-sum">{{ $deal->isDirect() && $deal->owner_price === null ? 'уточним' : Money::rub((int) $deal->ownerPrice()) }}</span>
                </div>
                @if ($bill)
                    <div class="list">
                        <x-ui.doc :doc="\App\Support\Docs::media($bill)" class="row">
                            <x-ui.file-icon :name="$bill->file_name" :mime="$bill->mime_type" :bytes="$bill->size"/>
                            <span class="min-w-0 flex-1 truncate">Счёт поставщика <span class="text-ink-muted">от {{ \App\Support\Ago::day($bill->created_at) }}</span></span>
                            <x-ui.chevron/>
                        </x-ui.doc>
                    </div>
                @endif
            @elseif ($kind === 'owner')
                <div>
                    <span class="block break-words">{{ $scheme->payeeName($offer, $contract?->seller?->name) }}</span>
                    <span class="block text-sm text-ink-muted">платит {{ $buyerWho }} по ДКП</span>
                    <span class="nums mt-1 block pay-sum">{{ $sum($deal->ownerPrice()) }}</span>
                    @if ($deal->offset())<span class="block text-sm text-ink-muted">взаимозачёт со страховой −{{ Money::rub($deal->offset()) }}</span>@endif
                </div>
            @else
                <div>
                    <span class="block">{{ $offer->vendor?->name ?? 'Поставщик' }}</span>
                    <span class="block text-sm text-ink-muted">платит менеджер</span>
                    <span class="nums mt-1 block pay-sum">{{ $sum($deal->supplierCost()) }}</span>
                </div>
            @endif
        </div>
    </x-ui.card>
@endif
