{{-- Платёж поставщику по договору комиссии (14.11.2026, владелец: «посмотри, какая там полезная инфа, которую мы не
     используем» — выбрал реквизиты с назначением и сверку суммы): назначенная цена (п. 2.4), назначение платежа и реквизиты
     получателя — из присланного «Договора комиссии» (`FillCommissionOwner`, `deal_contracts.supplier_*`). С 15.11.2026 —
     строками общей группы «Денег» (владелец: «ты реально считаешь, что это хороший дизайн?» — своя плашка с подписью
     «По договору комиссии» и «Закупочная» тем же числом ниже): «Поставщику» и сумма (нажатие копирует), под ней назначение
     (нажатие копирует), «Реквизиты» — шторкой, каждый нажатием. Сумма не сходится с тем, что мы собираемся перевести
     (`Deal::supplierCost`), — красным, с обеими суммами. Нечего показать — не рисуется. --}}
@props(['deal'])
@php
    use App\Support\Money;
    $contract = $deal->contract;
    $price = $contract?->supplier_price;
    $purpose = $contract?->supplier_purpose;
    $payee = $contract?->supplier_requisites ?: [];
    $ours = $deal->supplierCost();
    $diff = $price !== null && $ours !== null ? $ours - $price : 0;
    $rows = array_filter(['Получатель' => $payee['name'] ?? null, 'ИНН' => $payee['inn'] ?? null, 'КПП' => $payee['kpp'] ?? null,
        'Расчётный счёт' => $payee['account'] ?? null, 'Банк' => $payee['bank'] ?? null, 'БИК' => $payee['bik'] ?? null, 'Корр. счёт' => $payee['corr'] ?? null]);
    $sheet = 'supplier-pay-'.$deal->id;
@endphp
@if ($price !== null || $purpose)
    <div class="row items-start">
        <span class="min-w-0 flex-1">
            <span class="block">Поставщику</span>
            @if ($diff !== 0)<span class="row-sub text-danger">у нас {{ Money::rub($ours) }}, разница {{ Money::rub(abs($diff)) }}</span>@endif
            @if ($purpose)<x-ui.copy-text class="row-sub text-left" done="Назначение в буфере">{{ $purpose }}</x-ui.copy-text>@endif
        </span>
        @if ($price !== null)
            <x-ui.copy-text class="nums shrink-0 {{ $diff !== 0 ? 'text-danger' : '' }}" :value="(string) $price" done="Сумма в буфере">{{ Money::rub($price) }}</x-ui.copy-text>
        @endif
    </div>
@endif
@if ($rows)
    <div data-controller="sheet" class="contents">
        <x-ui.sheet-row>
            <span class="min-w-0 flex-1">Реквизиты</span>
            <x-ui.chevron/>
        </x-ui.sheet-row>
        <x-ui.sheet :id="$sheet" :title="'Реквизиты '.($payee['name'] ?? '')">
            <div class="list">
                @foreach ($rows as $label => $value)
                    <div class="row">
                        <span class="shrink-0 text-ink-muted">{{ $label }}</span>
                        <x-ui.copy-text class="nums min-w-0 flex-1 text-right" done="{{ $label }} в буфере">{{ $value }}</x-ui.copy-text>
                    </div>
                @endforeach
            </div>
        </x-ui.sheet>
    </div>
@endif
