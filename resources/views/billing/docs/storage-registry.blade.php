{{-- Акт хранения по реестру (счёт плательщику за месяц): стороны, таблица машин — ТС, госномер, VIN, убыток, парковка,
     период, сутки, ставка, сумма; несколько ставок у машины — несколько строк, прочие начисления — своей строкой.
     Альбомный лист dompdf (без flex, шрифты файлами); та же разметка для страницы печати. --}}
@php
    use App\Billing\ChargeKind;
    use App\Support\Money;
    $i = $invoice; $p = $i->party; $pdf = $pdf ?? false; $fonts = resource_path('fonts/pdf'); $embed = ! $pdf && request()->boolean('embed');
    $byCar = $i->charges->whereNotNull('vehicle_id')->groupBy('vehicle_id');
    $n = 0;
@endphp
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
        body { font-family: "Onest", sans-serif; font-size: 9.5px; color: #111; margin: 0; padding: 24px 28px; }
        .act { padding: 0; }
        @else
        body { font-family: -apple-system, "Onest", sans-serif; font-size: 12px; color: #111; margin: 0; padding: 24px; background: #f4f4f4; }
        .act { max-width: 1040px; margin: 0 auto; background: #fff; padding: 32px; }
        @endif
        @if ($embed)
        body { padding: 0; background: #fff; }
        .act { max-width: none; padding: 24px 20px; }
        @endif
        h1 { font-size: 17px; font-weight: 600; margin: 0 0 4px; }
        .sub { color: #555; margin: 0 0 12px; }
        p { margin: 8px 0; line-height: 1.4; }
        table.reg { width: 100%; border-collapse: collapse; margin-top: 10px; }
        .reg th, .reg td { text-align: left; padding: 4px 5px; border: 1px solid #ccc; vertical-align: top; }
        .reg thead { display: table-header-group; }
        .reg tr { page-break-inside: avoid; }
        .reg th { color: #444; font-weight: 600; background: #f2f2f2; }
        .n { text-align: right; white-space: nowrap; }
        .sign { width: 100%; margin-top: 36px; border-collapse: collapse; }
        .sign td { width: 50%; padding: 0 20px 0 0; border: 0; }
        .line { border-top: 1px solid #111; padding-top: 6px; margin-top: 30px; color: #555; }
        .print { position: fixed; top: 12px; right: 12px; }
        @media print { body { padding: 0; background: #fff; } .act { max-width: none; padding: 0; } .print { display: none; } }
    </style>
</head>
<body>
    @unless ($pdf || $embed)<div class="print"><button type="button" onclick="window.print()">Печать</button></div>@endunless
    <article class="act">
        <h1>Акт оказанных услуг хранения {{ $i->label() }}</h1>
        <p class="sub">{{ $i->issued_at->format('d.m.Y') }}, за {{ mb_strtolower($i->month->translatedFormat('F Y')) }}</p>
        <p><b>{{ $self->name }}</b> ({{ $self->details() }}), Исполнитель, и <b>{{ $p->name }}</b>{{ $p->details() ? ' ('.$p->details().')' : '' }}, Заказчик, составили настоящий акт о том, что Исполнитель оказал услуги по хранению транспортных средств по реестру:</p>
        <table class="reg">
            <thead><tr><th style="width:22px">№</th><th>Транспортное средство</th><th>Гос. номер</th><th>VIN</th><th>Номер убытка</th><th>№ полиса</th><th>Парковка</th><th>Период</th><th class="n">Сутки</th><th class="n">Ставка</th><th class="n">Сумма</th></tr></thead>
            <tbody>
            @foreach ($byCar as $rows)
                @php $v = $rows->first()->vehicle; $n++; $storage = $rows->where('kind', ChargeKind::Storage)->values(); $other = $rows->where('kind', '!=', ChargeKind::Storage)->values(); $span = max(1, $storage->count() + $other->count()); @endphp
                @foreach ($storage->concat($other) as $k => $c)
                    <tr>
                        @if ($k === 0)
                            <td rowspan="{{ $span }}">{{ $n }}</td>
                            <td rowspan="{{ $span }}">{{ $v?->titleWithYear() }}</td>
                            <td rowspan="{{ $span }}">{{ $v?->plate }}</td>
                            <td rowspan="{{ $span }}">{{ $v?->vin }}</td>
                            <td rowspan="{{ $span }}">{{ $v?->ref }}</td>
                            <td rowspan="{{ $span }}">{{ $v?->policy_no }}</td>
                            <td rowspan="{{ $span }}">{{ $v?->yard?->name }}</td>
                        @endif
                        @if ($c->kind === ChargeKind::Storage)
                            <td>{{ $c->period_from?->format('d.m.Y') }} – {{ $c->period_to?->format('d.m.Y') }}</td><td class="n">{{ (int) $c->qty }}</td>
                        @else
                            <td>{{ $c->title }}</td><td class="n">{{ rtrim(rtrim(number_format($c->qty, 2, '.', ''), '0'), '.') }} {{ $c->unitLabel() }}</td>
                        @endif
                        <td class="n">{{ Money::nums($c->price, 2) }}</td><td class="n">{{ Money::nums($c->amount, 2) }}</td>
                    </tr>
                @endforeach
            @endforeach
            <tr><td colspan="10"><b>Итого, {{ $n }} ТС</b></td><td class="n"><b>{{ Money::nums($i->charges->sum('amount'), 2) }} ₽</b></td></tr>
            @if ($i->vat_on_top)<tr><td colspan="10">{{ $i->vatLabel() }}</td><td class="n">{{ Money::nums($i->vatAmount(), 2) }} ₽</td></tr><tr><td colspan="10"><b>Всего</b></td><td class="n"><b>{{ Money::nums($i->total, 2) }} ₽</b></td></tr>@endif
            </tbody>
        </table>
        <p>{{ $i->vatRate() ? ($i->vat_on_top ? '' : $i->vatLabel().', '.Money::nums($i->vatAmount(), 2).' ₽. ') : 'НДС не облагается. ' }}Услуги оказаны в полном объёме, претензий по объёму, качеству и срокам Заказчик не имеет.</p>
        <table class="sign"><tr>
            <td><div class="line">Исполнитель {{ $self->director ? '/ '.$self->director : '' }}</div></td>
            <td><div class="line">Заказчик</div></td>
        </tr></table>
    </article>
</body>
</html>
