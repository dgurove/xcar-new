{{-- Акт оказанных услуг хранения: стороны, ТС, период, сутки, ставка, сумма. Та же разметка для PDF (dompdf: без flex, шрифты файлами) и для страницы печати. --}}
@php use App\Support\Money; $i = $invoice; $v = $i->vehicle; $p = $i->party; $storage = $i->charges->where('kind', \App\Billing\ChargeKind::Storage); $pdf = $pdf ?? false; $fonts = resource_path('fonts/pdf'); @endphp
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Акт хранения {{ $i->label() }}</title>
    <style>
        @if ($pdf)
        @font-face { font-family: "Onest"; font-weight: 400; src: url("file://{{ $fonts }}/Onest-Regular.ttf"); }
        @font-face { font-family: "Onest"; font-weight: 600; src: url("file://{{ $fonts }}/Onest-SemiBold.ttf"); }
        body { font-family: "Onest", sans-serif; font-size: 11px; color: #111; margin: 0; padding: 28px 32px; }
        .act { padding: 0; }
        @else
        body { font-family: -apple-system, "Onest", sans-serif; font-size: 13px; color: #111; margin: 0; padding: 24px; background: #f4f4f4; }
        .act { max-width: 720px; margin: 0 auto; background: #fff; padding: 32px; }
        @endif
        h1 { font-size: 20px; font-weight: 600; margin: 0 0 6px; }
        .sub { color: #555; margin: 0 0 18px; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th, td { text-align: left; padding: 6px 8px; border-bottom: 1px solid #ddd; vertical-align: top; }
        th { color: #555; font-weight: 500; width: 34%; }
        .n { text-align: right; white-space: nowrap; }
        p { margin: 10px 0; line-height: 1.45; }
        .sign { margin-top: 48px; }
        .sign td { width: 50%; padding: 0 20px 0 0; border: 0; }
        .line { border-top: 1px solid #111; padding-top: 6px; margin-top: 36px; color: #555; font-size: 12px; }
        .print { position: fixed; top: 12px; right: 12px; }
        @media print { body { padding: 0; background: #fff; } .act { max-width: none; padding: 0; } .print { display: none; } }
    </style>
</head>
<body>
    @unless ($pdf)<div class="print"><button type="button" onclick="window.print()">Печать</button></div>@endunless
    <article class="act">
        <h1>Акт оказанных услуг хранения {{ $i->label() }}</h1>
        <p class="sub">{{ $i->issued_at->format('d.m.Y') }}</p>
        <p><b>{{ $self->name }}</b> ({{ $self->details() }}), Исполнитель, и <b>{{ $p->name }}</b>{{ $p->details() ? ' ('.$p->details().')' : '' }}, Заказчик, составили настоящий акт о том, что Исполнитель оказал услуги по хранению транспортного средства:</p>
        <table>
            <tr><th>Транспортное средство</th><td>{{ $v?->titleWithYear() }}</td></tr>
            @if ($v?->vin)<tr><th>VIN</th><td>{{ $v->vin }}</td></tr>@endif
            @if ($v?->plate)<tr><th>Гос. номер</th><td>{{ $v->plate }}</td></tr>@endif
            @if ($v?->ref)<tr><th>Номер убытка</th><td>{{ $v->ref }}</td></tr>@endif
            @if ($v?->yard)<tr><th>Стоянка</th><td>{{ implode(', ', array_filter([$v->yard->name, $v->yard->address])) }}</td></tr>@endif
        </table>
        <table>
            <tr><th>Период</th><th class="n">Сутки</th><th class="n">Ставка</th><th class="n">Сумма</th></tr>
            @foreach ($storage as $c)
                <tr><td>{{ $c->period_from?->format('d.m.Y') }} – {{ $c->period_to?->format('d.m.Y') }}</td><td class="n">{{ (int) $c->qty }}</td><td class="n">{{ Money::nums($c->price, 2) }}</td><td class="n">{{ Money::nums($c->amount, 2) }}</td></tr>
            @endforeach
            @foreach ($i->charges->where('kind', '!=', \App\Billing\ChargeKind::Storage) as $c)
                <tr><td>{{ $c->title }}</td><td class="n">{{ rtrim(rtrim(number_format($c->qty, 2, '.', ''), '0'), '.') }} {{ $c->unitLabel() }}</td><td class="n">{{ Money::nums($c->price, 2) }}</td><td class="n">{{ Money::nums($c->amount, 2) }}</td></tr>
            @endforeach
            <tr><td colspan="3"><b>Итого</b></td><td class="n"><b>{{ Money::nums($i->total, 2) }} ₽</b></td></tr>
        </table>
        <p>{{ $i->vat ? 'В том числе НДС '.\App\Billing\Invoice::VAT.' % — '.Money::nums($i->vatAmount(), 2).' ₽.' : 'НДС не облагается.' }} Услуги оказаны в полном объёме, претензий по объёму, качеству и срокам Заказчик не имеет.</p>
        <table class="sign"><tr>
            <td><div class="line">Исполнитель {{ $self->director ? '/ '.$self->director : '' }}</div></td>
            <td><div class="line">Заказчик</div></td>
        </tr></table>
    </article>
</body>
</html>
