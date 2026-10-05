{{-- Договор купли-продажи ТС двух физлиц (05.10.2026, сделка «страхователю по ДКП»): продавец — собственник по СТС
     (паспорт со сканов страховой вносит сотрудник), покупатель — покупатель менеджера. Стандартная форма: стороны,
     ТС таблицей, цена цифрами и прописью, «деньги получил, ТС получил», три экземпляра. Чего нет — прочерк. Одна
     разметка на страницу печати (`?embed=1` — в шторке документов) и PDF (`Support\Pdf`, `$pdf`): таблицы, без flex. --}}
@php
    use App\Support\Money;
    use App\Support\Words;
    $deal = $contract->deal; $offer = $deal->offer; $s = $contract->seller; $b = $contract->buyerParty();
    $blank = '________________';
    $price = $contract->price;
    $person = function ($p) use ($blank) {
        if (! $p) {
            return $blank.', дата рождения '.$blank.', паспорт '.$blank.', выдан '.$blank.', зарегистрирован(а) по адресу: '.$blank;
        }
        return implode(', ', array_filter([
            '<b>'.e($p->name ?: $blank).'</b>',
            'дата рождения '.($p->birth_at?->format('d.m.Y') ?? $blank).($p->birth_place ? ', место рождения '.e($p->birth_place) : ''),
            'паспорт '.e($p->passport ?: $blank).' выдан '.e($p->passport_issued ?: $blank).' '.($p->passport_issued_at?->format('d.m.Y') ?? $blank).($p->passport_code ? ', код подразделения '.e($p->passport_code) : ''),
            'зарегистрирован(а) по адресу: '.e($p->reg_address ?: $blank),
        ]));
    };
    $car = mb_strtoupper(trim(($offer->brand?->name ?? '').' '.($offer->model?->name ?? '')));
    $rows = [
        'Марка, модель' => $car ?: null,
        'Идентификационный номер (VIN)' => $offer->vin,
        'Год выпуска' => $offer->year,
        'Кузов №' => $contract->body_no ?: $offer->vin,
        'Шасси (рама) №' => $contract->chassis_no ?: 'отсутствует',
        'Двигатель №' => $contract->engine_no,
        'Цвет' => $offer->color ? mb_strtoupper($offer->color) : null,
        'Государственный регистрационный знак' => $contract->plate,
        'Паспорт ТС' => trim(($contract->pts ?? '').($contract->pts_issued ? ', выдан '.$contract->pts_issued : '')) ?: null,
        'Свидетельство о регистрации ТС' => $contract->sts,
    ];
    $date = $contract->signed_at ? $contract->signed_at->translatedFormat('«j» F Y г.') : '«___» ______________ 20___ г.';
    $embed = ! ($pdf ?? false) && request()->boolean('embed');
@endphp
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ДКП {{ $offer->titleWithYear() }}</title>
    <style>
        body { font-family: {{ ($pdf ?? false) ? '"Onest", sans-serif' : '"Times New Roman", serif' }}; font-size: 12px; color: #000; margin: 0; padding: 24px; background: #f4f4f4; line-height: 1.45; }
        .doc { max-width: 720px; margin: 0 auto; background: #fff; padding: 36px 40px; }
        h1 { font-size: 14px; text-align: center; margin: 0 0 12px; text-transform: uppercase; }
        p { margin: 6px 0; text-align: justify; }
        table { width: 100%; border-collapse: collapse; margin: 8px 0; }
        .car td, .car th { border: 1px solid #000; padding: 4px 6px; vertical-align: top; text-align: left; font-weight: normal; }
        .car th { width: 46%; }
        .head td { padding: 0; }
        .sign td { padding: 18px 8px 0 0; vertical-align: top; width: 50%; }
        .line { border-bottom: 1px solid #000; height: 18px; }
        .print { position: fixed; top: 12px; right: 12px; }
        @if ($embed)
        body { padding: 0; background: #fff; }
        .doc { max-width: none; padding: 24px 20px; }
        @endif
        @if ($pdf ?? false)
        body { padding: 0; background: #fff; font-size: 11px; }
        .doc { max-width: none; padding: 0; }
        @endif
        @media print { body { padding: 0; background: #fff; } .doc { max-width: none; padding: 0; } .print { display: none; } }
    </style>
</head>
<body>
    @unless ($embed || ($pdf ?? false))<div class="print"><button type="button" onclick="window.print()">Печать</button></div>@endunless
    <article class="doc">
        <h1>Договор купли-продажи транспортного средства</h1>
        <table class="head"><tr><td>г. {{ $contract->city ?: '______________' }}</td><td style="text-align:right">{{ $date }}</td></tr></table>
        <p>{!! $person($s) !!}, именуемый(ая) в дальнейшем «Продавец», с одной стороны, и {!! $person($b) !!}, именуемый(ая) в дальнейшем «Покупатель», с другой стороны, заключили настоящий договор о нижеследующем.</p>
        <p>1. Продавец передаёт в собственность Покупателя, а Покупатель принимает и оплачивает транспортное средство (далее — ТС):</p>
        <table class="car">
            @foreach ($rows as $label => $value)
                <tr><th>{{ $label }}</th><td>{{ $value ?: '—' }}</td></tr>
            @endforeach
        </table>
        <p>2. ТС принадлежит Продавцу на праве собственности, что подтверждается паспортом ТС и свидетельством о регистрации ТС, указанными в п. 1.</p>
        <p>3. До заключения настоящего договора ТС никому не продано, не заложено, в споре и под арестом (запрещением) не состоит.</p>
        <p>4. ТС передаётся в повреждённом состоянии. Покупатель осмотрел ТС, его техническое состояние и комплектность ему известны, претензий Покупатель не имеет.</p>
        <p>5. Стоимость ТС составляет <b>{{ $price ? Money::nums($price).' ('.Words::rub($price).') рублей 00 копеек' : '________________ рублей' }}</b>.</p>
        <p>6. Покупатель передал, а Продавец получил денежные средства в сумме, указанной в п. 5, полностью. Продавец передал, а Покупатель получил ТС, ключи и документы на него: паспорт ТС и свидетельство о регистрации ТС.</p>
        <p>7. Право собственности на ТС переходит к Покупателю с момента подписания настоящего договора. Покупатель в течение 10 дней обязан изменить регистрационные данные о собственнике ТС в ГИБДД.</p>
        <p>8. Договор вступает в силу с момента подписания и составлен в трёх экземплярах, имеющих равную юридическую силу: по одному для Продавца, Покупателя и ГИБДД.</p>
        <table class="sign">
            <tr>
                <td><b>Продавец</b><br>Деньги в сумме, указанной в п. 5, получил, ТС передал<div class="line"></div>{{ $s?->name ?: '' }}</td>
                <td><b>Покупатель</b><br>Деньги передал, ТС и документы получил<div class="line"></div>{{ $b?->name ?: '' }}</td>
            </tr>
        </table>
    </article>
</body>
</html>
