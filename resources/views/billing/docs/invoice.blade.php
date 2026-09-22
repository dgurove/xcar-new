{{-- Счёт на оплату: та же разметка для PDF (dompdf: без flex и grid, шрифты файлами) и для страницы печати. --}}
@php
    use App\Support\Money;
    $fonts = resource_path('fonts/pdf');
    $p = $invoice->party;
    $lines = $invoice->charges;
    // Удержанное менеджером вознаграждение — зачёт в момент выставления: печатается и «к оплате».
    $offset = $invoice->payments->where('source', \App\Billing\PaymentSource::Offset)->sum('amount');
@endphp
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <title>Счёт {{ $invoice->label() }}</title>
    <style>
        @if ($pdf ?? false)
        @font-face { font-family: "Onest"; font-weight: 400; src: url("file://{{ $fonts }}/Onest-Regular.ttf"); }
        @font-face { font-family: "Onest"; font-weight: 600; src: url("file://{{ $fonts }}/Onest-SemiBold.ttf"); }
        @endif
        body { font-family: "Onest", -apple-system, sans-serif; font-size: 11px; color: #111; margin: 0; padding: 28px 32px; }
        h1 { font-size: 18px; font-weight: 600; margin: 0 0 14px; }
        table { width: 100%; border-collapse: collapse; }
        .req td { padding: 3px 6px; vertical-align: top; border: 1px solid #999; }
        .req td.l { width: 26%; color: #555; }
        .lines { margin-top: 18px; }
        .lines th { text-align: left; font-weight: 600; border-bottom: 1px solid #111; padding: 6px 6px; }
        .lines td { border-bottom: 1px solid #ddd; padding: 6px 6px; }
        .n { text-align: right; white-space: nowrap; }
        .total { margin-top: 12px; text-align: right; font-size: 13px; }
        .total b { font-weight: 600; }
        .note { margin-top: 18px; color: #555; }
        .sign { margin-top: 40px; }
        .sign td { padding: 6px 0; }
        .line { border-bottom: 1px solid #111; height: 22px; width: 220px; display: inline-block; }
        .print { position: fixed; top: 12px; right: 12px; }
        @media print { .print { display: none; } }
    </style>
</head>
<body>
    @unless ($pdf ?? false)<div class="print"><button type="button" onclick="window.print()">Печать</button></div>@endunless
    <table class="req">
        <tr><td class="l">Получатель</td><td><b>{{ $self->name }}</b><br>{{ $self->details() }}</td></tr>
        <tr><td class="l">Банк получателя</td><td>{{ $self->bankDetails() ?: '—' }}</td></tr>
    </table>
    <h1 style="margin-top:18px">Счёт на оплату {{ $invoice->label() }} от {{ $invoice->issued_at->format('d.m.Y') }}</h1>
    <table class="req">
        <tr><td class="l">Плательщик</td><td><b>{{ $p->name }}</b>@if ($p->details())<br>{{ $p->details() }}@endif</td></tr>
        @if ($invoice->vehicle)<tr><td class="l">Основание</td><td>{{ $invoice->vehicle->titleWithYear() }}{{ $invoice->vehicle->vin ? ', VIN '.$invoice->vehicle->vin : '' }}{{ $invoice->vehicle->ref ? ', убыток '.$invoice->vehicle->ref : '' }}{{ $invoice->vehicle->contract_no ? ', договор № '.$invoice->vehicle->contract_no : '' }}</td></tr>@endif
        @if ($invoice->deal)<tr><td class="l">Основание</td><td>Сделка по предложению № {{ $invoice->deal->offer?->number }}{{ $invoice->deal->offer ? ', '.$invoice->deal->offer->titleWithYear() : '' }}</td></tr>@endif
        <tr><td class="l">Оплатить до</td><td>{{ $invoice->due_at->format('d.m.Y') }}</td></tr>
    </table>
    <table class="lines">
        <thead><tr><th style="width:24px">№</th><th>Наименование</th><th class="n">Кол-во</th><th class="n">Ед.</th><th class="n">Цена</th><th class="n">Сумма</th></tr></thead>
        <tbody>
        @foreach ($lines as $i => $c)
            <tr><td>{{ $i + 1 }}</td><td>{{ $c->title }}</td><td class="n">{{ rtrim(rtrim(number_format($c->qty, 2, '.', ' '), '0'), '.') }}</td><td class="n">{{ $c->unitLabel() }}</td><td class="n">{{ Money::nums($c->price, 2) }}</td><td class="n">{{ Money::nums($c->amount, 2) }}</td></tr>
        @endforeach
        </tbody>
    </table>
    <div class="total">
        Итого: <b>{{ Money::nums($invoice->total, 2) }} ₽</b><br>
        {{ $invoice->vat ? 'В том числе НДС '.\App\Billing\Invoice::VAT.' %: '.Money::nums($invoice->vatAmount(), 2).' ₽' : 'НДС не облагается' }}
        @if ($offset > 0)<br>Зачёт агентского вознаграждения: {{ Money::nums($offset, 2) }} ₽<br>К оплате: <b>{{ Money::nums($invoice->total - $offset, 2) }} ₽</b>@endif
    </div>
    <div class="note">Всего наименований {{ $lines->count() }}, на сумму {{ Money::nums($invoice->total, 2) }} ₽</div>
    @if ($invoice->notes)<div class="note">{{ $invoice->notes }}</div>@endif
    <table class="sign">
        <tr><td>Руководитель <span class="line"></span> {{ $self->director ?? '' }}</td></tr>
    </table>
</body>
</html>
