{{-- Акты хранения по каждой ТС реестра одним документом — лист на машину (Питер, 09.10.2026: Альфа просит «акты
     хранения по всем ТС» за месяц): стороны, ТС, период, сутки, цена, сумма. Страница печати и шторка документов. --}}
@php
    use App\Billing\ChargeKind;
    use App\Support\Money;
    $i = $invoice; $p = $i->party; $embed = request()->boolean('embed');
    $byCar = $i->charges->whereNotNull('vehicle_id')->groupBy('vehicle_id');
    $vendor = $byCar->first()?->first()?->vehicle?->vendor;
@endphp
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Акты хранения {{ $i->label() }}</title>
    <style>
        body { font-family: -apple-system, "Onest", sans-serif; font-size: 13px; color: #111; margin: 0; padding: 24px; background: #f4f4f4; }
        .act { max-width: 720px; margin: 0 auto 24px; background: #fff; padding: 32px; page-break-after: always; }
        .act:last-child { page-break-after: auto; }
        @if ($embed)
        body { padding: 0; background: #fff; }
        .act { max-width: none; padding: 24px 20px; margin: 0; border-bottom: 1px solid #ddd; }
        @endif
        h1 { font-size: 20px; font-weight: 600; margin: 0 0 6px; }
        .sub { color: #555; margin: 0 0 18px; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th, td { text-align: left; padding: 6px 8px; border-bottom: 1px solid #ddd; vertical-align: top; }
        th { color: #555; font-weight: 500; width: 34%; }
        .n { text-align: right; white-space: nowrap; }
        p { margin: 10px 0; line-height: 1.45; }
        .sign td { width: 50%; padding: 0 20px 0 0; border: 0; }
        .line { border-top: 1px solid #111; padding-top: 6px; margin-top: 36px; color: #555; font-size: 12px; }
        .print { position: fixed; top: 12px; right: 12px; }
        @media print { body { padding: 0; background: #fff; } .act { max-width: none; padding: 0; margin: 0; } .print { display: none; } }
    </style>
</head>
<body>
    @unless ($embed)<div class="print"><button type="button" onclick="window.print()">Печать</button></div>@endunless
    @foreach ($byCar as $rows)
        @php $v = $rows->first()->vehicle; $sum = (float) $rows->sum('amount'); @endphp
        <article class="act">
            <h1>Акт хранения ТС</h1>
            <p class="sub">к отчёт-акту {{ $i->label() }}@if ($vendor?->agreement_number), договор № {{ $vendor->agreement_number }}@if ($vendor->agreement_date) от {{ $vendor->agreement_date->format('d.m.Y') }}@endif @endif</p>
            <p><b>{{ $self->name }}</b>, Хранитель, и <b>{{ $p->name }}</b>, Поклажедатель, составили настоящий акт о том, что Хранитель оказал услуги хранения транспортного средства{{ $v?->released_at ? ' и передал его после хранения' : ' за указанный период' }}:</p>
            <table>
                <tr><th>Марка ТС</th><td>{{ $v?->titleWithYear() }}</td></tr>
                @if ($v?->vin)<tr><th>VIN</th><td>{{ $v->vin }}</td></tr>@endif
                @if ($v?->plate)<tr><th>Гос. номер</th><td>{{ $v->plate }}</td></tr>@endif
                @if ($v?->ref)<tr><th>№ убытка</th><td>{{ $v->ref }}</td></tr>@endif
                @if ($v?->policy_no)<tr><th>№ полиса</th><td>{{ $v->policy_no }}</td></tr>@endif
                <tr><th>Принято к хранению</th><td>{{ $v?->accepted_at?->format('d.m.Y') }}</td></tr>
                @if ($v?->released_at)<tr><th>Передано после хранения</th><td>{{ $v->released_at->format('d.m.Y') }}</td></tr>@endif
                @if ($v?->yard)<tr><th>Парковка</th><td>{{ implode(', ', array_filter([$v->yard->name, $v->yard->address])) }}</td></tr>@endif
            </table>
            <table>
                <tr><th>Период</th><th class="n">Сутки</th><th class="n">Цена</th><th class="n">Стоимость</th></tr>
                @foreach ($rows as $c)
                    <tr>
                        <td>{{ $c->kind === ChargeKind::Storage ? $c->period_from?->format('d.m.Y').' – '.$c->period_to?->format('d.m.Y') : $c->title }}</td>
                        <td class="n">{{ $c->kind === ChargeKind::Storage ? (int) $c->qty : rtrim(rtrim(number_format($c->qty, 2, '.', ''), '0'), '.').' '.$c->unitLabel() }}</td>
                        <td class="n">{{ Money::nums($c->price, 2) }}</td><td class="n">{{ Money::nums($c->amount, 2) }}</td>
                    </tr>
                @endforeach
                <tr><td colspan="3"><b>Итого</b></td><td class="n"><b>{{ Money::nums($sum, 2) }} ₽</b></td></tr>
            </table>
            <table class="sign"><tr>
                <td><div class="line">Хранитель {{ $self->director ? '/ '.$self->director : '' }}</div></td>
                <td><div class="line">Поклажедатель</div></td>
            </tr></table>
        </article>
    @endforeach
</body>
</html>
