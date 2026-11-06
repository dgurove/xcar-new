{{-- Счёт на оплату (31.10.2026, по форме владельца — как счёт dgurov.com): логотип XCar, банковский блок и QR справа от
     него, заголовок, Поставщик / Покупатель / Основание, таблица, итог, сумма прописью, условия, подпись — подряд, без
     пояснений и повторов: реквизиты продавца только в банковском блоке и в «Поставщике». Кеглей три: 7,5 подписи и шапка
     таблицы, 9 текст и итог (жирным), 14 заголовок. Та же разметка для PDF (dompdf:
     без flex и grid — таблицами, шрифты и логотип файлами) и для страницы печати. --}}
@php
    use App\Support\Money;
    $pdf = $pdf ?? false;
    $fonts = resource_path('fonts/pdf');
    $p = $invoice->party;
    // Реестр за месяц — одной строкой (владелец 08.10.2026), машины — в акте. Слова — как в прежних счетах страховым (архив
    // «СТОЯНКА», 05.11.2026): услуга за месяц на основании договора согласно акту, «Кол-во» — число ТС, цены нет.
    $registry = $invoice->isRegistry();
    $tow = $invoice->kind === \App\Billing\ChargeKind::Tow;
    $vendor = $registry ? $invoice->registryVehicles()->first()?->vendor : null;
    $agreement = $tow && $vendor?->tow_agreement_number ? [$vendor->tow_agreement_number, $vendor->tow_agreement_date] : [$vendor?->agreement_number, $vendor?->agreement_date];
    $contract = $agreement[0] ? 'Договор № '.$agreement[0].($agreement[1] ? ' от '.$agreement[1]->format('d.m.Y').' г.' : '') : null;
    $lines = $registry ? collect([new \App\Billing\Charge(['title' => ($tow ? 'Услуги по эвакуации транспортных средств' : 'Услуги по хранению автотранспорта').' за '.mb_strtolower($invoice->month->translatedFormat('F Y')).' г.'
        .($contract ? ' на основании '.str_replace('Договор', 'Договора', $contract) : '').' согласно акту № '.$invoice->docNo().' от '.$invoice->issued_at->format('d.m.Y'),
        'qty' => $invoice->registryVehicles()->count(), 'unit' => 'ts', 'price' => null, 'amount' => $invoice->charges->sum('amount')])]) : $invoice->charges;
    // Удержанное менеджером вознаграждение — зачёт в момент выставления: печатается и «к оплате».
    $offset = $invoice->payments->where('source', \App\Billing\PaymentSource::Offset)->sum('amount');
    $due = $invoice->total - $offset;
    // QR для приложения банка: реквизиты, сумма к оплате и назначение, по которому выписка найдёт счёт.
    $qr = \App\Billing\Documents\PaymentQr::text($invoice, $self);
    $bank = $self->bank_name && $self->account && $self->bik;
    // Логотип в документах — XCar у всех продавцов (31.10.2026, владелец: Park — только на сайте). PNG, снятый с SVG шапки:
    // обрезку (`clip-path`) логотипа dompdf рисует криво.
    $logo = public_path('images/xcar-doc.png');
    // В шторке документов (?embed=1) — только лист: печать у шторки.
    $embed = ! $pdf && request()->boolean('embed');
    $basis = match (true) {
        (bool) $invoice->vehicle => $invoice->vehicle->titleWithYear().($invoice->vehicle->vin ? ', VIN '.$invoice->vehicle->vin : '').($invoice->vehicle->ref ? ', убыток '.$invoice->vehicle->ref : '').($invoice->vehicle->contract_no ? ', договор № '.$invoice->vehicle->contract_no : ''),
        $registry => $contract ?? ($tow ? 'Эвакуация транспортных средств' : 'Хранение транспортных средств'),
        (bool) $invoice->deal => 'Сделка по предложению № '.$invoice->deal->offer?->number.($invoice->deal->offer ? ', '.$invoice->deal->offer->titleWithYear() : ''),
        default => null,
    };
    $nb = fn (?string $s) => str_replace('№ ', '№&nbsp;', e((string) $s));
@endphp
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <title>Счёт {{ $invoice->label() }}</title>
    <style>
        @if ($pdf)
        @font-face { font-family: "Onest"; font-weight: 400; src: url("file://{{ $fonts }}/Onest-Regular.ttf"); }
        @font-face { font-family: "Onest"; font-weight: 500; src: url("file://{{ $fonts }}/Onest-Medium.ttf"); }
        @font-face { font-family: "Onest"; font-weight: 600; src: url("file://{{ $fonts }}/Onest-SemiBold.ttf"); }
        @font-face { font-family: "Onest"; font-weight: 700; src: url("file://{{ $fonts }}/Onest-SemiBold.ttf"); }
        @page { size: A4; margin: 14mm 14mm 14mm 18mm; }
        body { margin: 0; }
        @else
        @font-face { font-family: "Onest"; font-weight: 100 900; font-display: swap; src: url("/fonts/onest-var.woff2") format("woff2"); }
        body { margin: 0 auto; padding: 14mm 14mm 14mm 18mm; max-width: 210mm; box-sizing: border-box; background: #fff; }
        @endif
        body { font-family: "Onest", -apple-system, sans-serif; font-size: 9pt; line-height: 1.2; color: #1d1d1b; }
        table { width: 100%; border-collapse: collapse; }
        td, th { vertical-align: top; text-align: left; font-weight: 400; padding: 0; }
        b { font-weight: 600; }
        .muted { color: #808080; }
        .r { text-align: right; white-space: nowrap; }
        .logo { height: 7mm; width: auto; display: block; margin-bottom: 4mm; }
        .bank td { border: 1px solid #d0d0d0; padding: .9mm 2mm; }
        .bank .k { width: 21mm; white-space: nowrap; }
        .bank .v { width: 44mm; }
        .bank small { display: block; font-size: 7.5pt; line-height: 1.1; color: #808080; margin-top: .2mm; }
        .qr { width: 34mm; padding-left: 4mm; }
        .qr img { width: 34mm; height: 34mm; display: block; }
        h1 { font-size: 14pt; font-weight: 500; margin: 6mm 0 0; padding-bottom: 2.5mm; border-bottom: 1.5px solid #1d1d1b; }
        .parties { margin-top: 3mm; }
        .parties td { padding: .8mm 0; }
        .parties td.l { width: 28mm; color: #808080; }
        .items { margin-top: 5mm; }
        .items th { font-size: 7.5pt; color: #808080; padding: 1.2mm 2mm; border-top: 1px solid #d0d0d0; border-bottom: 1px solid #d0d0d0; }
        .items td { padding: 1.6mm 2mm; border-bottom: 1px solid #d0d0d0; }
        .items .n { width: 7mm; }
        .total { margin-top: 3mm; }
        .total td { padding: .4mm 0; text-align: right; }
        .total td.s { width: 38mm; white-space: nowrap; }
        .total .main td { font-weight: 600; }
        .words { margin-top: 3mm; }
        .terms { margin: 3mm 0 0; padding: 0 0 4mm 4.5mm; border-bottom: 1.5px solid #1d1d1b; }
        .terms li { padding-left: .5mm; }
        .sign { margin-top: 10mm; width: auto; }
        .sign td { vertical-align: bottom; padding-right: 3mm; }
        .sign .line { width: 55mm; border-bottom: 1px solid #1d1d1b; height: 6mm; }
        .print { position: fixed; top: 12px; right: 12px; }
        @media print { .print { display: none; } body { padding: 0; } }
    </style>
</head>
<body>
    @unless ($pdf || $embed)<div class="print"><button type="button" onclick="window.print()">Печать</button></div>@endunless
    @if (is_file($logo))<img class="logo" src="{{ $pdf ? $logo : '/images/'.basename($logo) }}" alt="">@endif

    @if ($bank)
        <table><tr>
            <td>
                <table class="bank">
                    <tr><td colspan="2" rowspan="2">{{ $self->bank_name }}<small>Банк получателя</small></td><td class="k">БИК</td><td class="v">{{ $self->bik }}</td></tr>
                    <tr><td class="k">Корр. счёт</td><td class="v">{{ $self->corr_account }}</td></tr>
                    {{-- ИНН и КПП — своими ячейками; у ИП КПП нет — ИНН на обе. --}}
                    <tr>@if ($self->kpp)<td>ИНН {{ $self->inn }}</td><td>КПП {{ $self->kpp }}</td>@else<td colspan="2">ИНН {{ $self->inn }}</td>@endif<td class="k" rowspan="2">Расч. счёт</td><td class="v" rowspan="2">{{ $self->account }}</td></tr>
                    <tr><td colspan="2">{{ $self->name }}<small>Получатель</small></td></tr>
                </table>
            </td>
            @if ($qr)<td class="qr"><img src="{{ \App\Support\Qr::pngDataUri($qr, 8, 0) }}" alt=""></td>@endif
        </tr></table>
    @endif

    <h1>Счёт на оплату {!! $nb($invoice->label()) !!} от {{ $invoice->issued_at->locale('ru')->isoFormat('D MMMM YYYY') }} г.</h1>

    <table class="parties">
        <tr><td class="l">Поставщик</td><td>{{ $self->name }}@if ($self->details()), {{ $self->details() }}@endif</td></tr>
        <tr><td class="l">Покупатель</td><td>{{ $p->title() }}@if ($p->details()), {{ $p->details() }}@endif</td></tr>
        @if ($basis)<tr><td class="l">Основание</td><td>{!! $nb($basis) !!}</td></tr>@endif
    </table>

    <table class="items">
        <tr><th class="n">№</th><th>Наименование</th><th class="r">Кол-во</th><th class="r">Ед.</th><th class="r">Цена, ₽</th><th class="r">Сумма, ₽</th></tr>
        @foreach ($lines as $i => $c)
            <tr><td class="n">{{ $i + 1 }}</td><td>{!! $nb($c->title) !!}</td><td class="r">{{ \App\Support\Qty::format($c->qty, '.', ' ') }}</td><td class="r">{{ $c->unitLabel() }}</td><td class="r">{{ $c->price === null ? '' : Money::nums($c->price, 2) }}</td><td class="r">{{ Money::nums($c->amount, 2) }}</td></tr>
        @endforeach
    </table>

    {{-- Итог: одна главная строка — сколько платить; НДС и зачёт — обычными строками над ней. --}}
    <table class="total">
        @if ($invoice->vat_on_top)
            <tr><td>Итого без НДС:</td><td class="s">{{ Money::nums($invoice->net(), 2) }} ₽</td></tr>
            <tr><td>{{ $invoice->vatLabel() }}:</td><td class="s">{{ Money::nums($invoice->vatAmount(), 2) }} ₽</td></tr>
        @endif
        @if ($offset > 0)
            <tr><td>Итого:</td><td class="s">{{ Money::nums($invoice->total, 2) }} ₽</td></tr>
            <tr><td>Зачёт агентского вознаграждения:</td><td class="s">{{ Money::nums($offset, 2) }} ₽</td></tr>
        @endif
        <tr class="main"><td>{{ $offset > 0 || $invoice->vat_on_top ? 'Всего к оплате' : 'Итого к оплате' }}:</td><td class="s">{{ Money::nums($due, 2) }} ₽</td></tr>
        @unless ($invoice->vat_on_top)
            <tr><td colspan="2">{{ $invoice->vatRate() ? $invoice->vatLabel().': '.Money::nums($invoice->vatAmount(), 2).' ₽' : 'НДС не облагается' }}</td></tr>
        @endunless
    </table>

    <div class="words">Всего наименований {{ $lines->count() }}, на сумму {{ Money::nums($due, 2) }} руб.<br><b>{{ \App\Support\Words::money($due) }}</b></div>

    {{-- Условия — нумерованным списком обычным цветом, как в 1С (владелец 31.10.2026); назначение платежа остаётся: по нему
         выписка находит счёт. --}}
    <ol class="terms">
        @if ($invoice->due_at)<li>Оплатить до {{ $invoice->due_at->format('d.m.Y') }}</li>@endif
        @if ($invoice->notes)<li>{{ $invoice->notes }}</li>@endif
        <li>Назначение платежа: {!! $nb(\App\Billing\Documents\PaymentQr::purpose($invoice)) !!}</li>
    </ol>

    <table class="sign"><tr>
        <td><b>{{ $self->signerTitle() }}</b></td>
        <td><div class="line"></div></td>
        <td>{{ $self->director ?? '' }}</td>
    </tr></table>
</body>
</html>
