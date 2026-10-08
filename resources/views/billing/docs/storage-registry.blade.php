{{-- Отчёт-акт по реестру (счёт плательщику за месяц): стороны, договор вендора, таблица машин — VIN, даты приёма и
     передачи, убыток, полис, госномер, марка, услуга, сутки, цена, стоимость; несколько ставок у машины — несколько
     строк, прочие начисления — своей строкой.
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
    <title>Отчёт-акт {{ $i->label() }}</title>
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
        @php
            $vendor = $byCar->first()?->first()?->vehicle?->vendor;
            $service = $vendor?->kind === \App\Vendors\Kind::Insurer ? 'Хранение повреждённого ТС' : 'Хранение ТС';
            $from = $i->month?->copy()->startOfMonth(); $to = $i->month?->copy()->endOfMonth();
        @endphp
        {{-- Форма — как отчёт-акт, которым Питер сдавал хранение Альфе (09.10.2026): договор в заголовке, даты приёма и
             передачи, полис, наименование услуги, сутки и цена. --}}
        <h1>Отчёт-акт {{ $i->label() }}</h1>
        <p class="sub">сдачи-приёмки оказанных услуг за период с {{ $from?->format('d.m.Y') }} по {{ $to?->format('d.m.Y') }}@if ($vendor?->agreement_number) к договору № {{ $vendor->agreement_number }}@if ($vendor->agreement_date) от {{ $vendor->agreement_date->format('d.m.Y') }}@endif @endif</p>
        <p><b>{{ $p->name }}</b>{{ $p->details() ? ' ('.$p->details().')' : '' }}, Поклажедатель, и <b>{{ $self->name }}</b> ({{ $self->details() }}), Хранитель, составили настоящий отчёт-акт о том, что Хранитель оказал Поклажедателю услуги хранения транспортных средств, указанных в таблице, и окончил их оказание:</p>
        <table class="reg">
            <thead><tr><th style="width:22px">№ п/п</th><th>VIN ТС</th><th>Дата принятия ТС к хранению</th><th>Дата передачи ТС после хранения</th><th>№ убытка</th><th>№ полиса</th><th>Гос. номер</th><th>Марка ТС</th><th>Наименование услуги</th><th class="n">Кол-во сут.</th><th class="n">Цена</th><th class="n">Стоимость</th></tr></thead>
            <tbody>
            @foreach ($byCar as $rows)
                @php $v = $rows->first()->vehicle; $n++; $storage = $rows->where('kind', ChargeKind::Storage)->values(); $other = $rows->where('kind', '!=', ChargeKind::Storage)->values(); $span = max(1, $storage->count() + $other->count()); @endphp
                @foreach ($storage->concat($other) as $k => $c)
                    <tr>
                        @if ($k === 0)
                            <td rowspan="{{ $span }}">{{ $n }}</td>
                            <td rowspan="{{ $span }}">{{ $v?->vin }}</td>
                            <td rowspan="{{ $span }}">{{ $v?->accepted_at?->format('d.m.Y') }}</td>
                            <td rowspan="{{ $span }}">{{ $v?->released_at?->format('d.m.Y') }}</td>
                            <td rowspan="{{ $span }}">{{ $v?->ref }}</td>
                            <td rowspan="{{ $span }}">{{ $v?->policy_no }}</td>
                            <td rowspan="{{ $span }}">{{ $v?->plate }}</td>
                            <td rowspan="{{ $span }}">{{ trim(($v?->brand?->name ?? '').' '.($v?->model?->name ?? '')) }}</td>
                        @endif
                        @if ($c->kind === ChargeKind::Storage)
                            {{-- Период хранения в строке — когда он уже, чем приём и выдача (помесячный счёт, своя ставка части срока). --}}
                            @php $whole = $c->period_from?->isSameDay($v?->accepted_at) && ($c->period_to?->isSameDay($v?->released_at) ?? false); @endphp
                            <td>{{ $service }}@unless ($whole) за {{ $c->period_from?->format('d.m.Y') }} – {{ $c->period_to?->format('d.m.Y') }}@endunless</td><td class="n">{{ (int) $c->qty }}</td>
                        @else
                            <td>{{ $c->title }}</td><td class="n">{{ rtrim(rtrim(number_format($c->qty, 2, '.', ''), '0'), '.') }} {{ $c->unitLabel() }}</td>
                        @endif
                        <td class="n">{{ Money::nums($c->price, 2) }}</td><td class="n">{{ Money::nums($c->amount, 2) }}</td>
                    </tr>
                @endforeach
            @endforeach
            <tr><td colspan="11"><b>Итого, {{ $n }} ТС</b></td><td class="n"><b>{{ Money::nums($i->charges->sum('amount'), 2) }} ₽</b></td></tr>
            @if ($i->vat_on_top)<tr><td colspan="11">{{ $i->vatLabel() }}</td><td class="n">{{ Money::nums($i->vatAmount(), 2) }} ₽</td></tr><tr><td colspan="11"><b>Всего</b></td><td class="n"><b>{{ Money::nums($i->total, 2) }} ₽</b></td></tr>@endif
            </tbody>
        </table>
        <p>{{ $i->vatRate() ? ($i->vat_on_top ? '' : $i->vatLabel().', '.Money::nums($i->vatAmount(), 2).' ₽. ') : 'НДС не облагается. ' }}Услуги оказаны в полном объёме, претензий по объёму, качеству и срокам Поклажедатель не имеет.</p>
        <table class="sign"><tr>
            <td><div class="line">Хранитель {{ $self->director ? '/ '.$self->director : '' }}</div></td>
            <td><div class="line">Поклажедатель</div></td>
        </tr></table>
    </article>
</body>
</html>
