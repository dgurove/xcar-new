@php
    $at = $intake ? $vehicle->accepted_at : $vehicle->released_at;
    $title = $intake ? 'Акт приёма автомобиля на хранение' : 'Акт выдачи автомобиля с хранения';
    $damages = $inspection?->damages() ?: $vehicle->damages();
    $note = $inspection?->damage_note ?? $vehicle->damage_note;
    $shots = $vehicle->photos()->filter(fn ($m) => \App\Park\PhotoStage::of($m)->value === ($intake ? 'intake' : 'release'));
    $shots = $shots->isNotEmpty() ? $shots : ($intake ? $vehicle->visiblePhotos() : collect());
    $vendor = $vehicle->vendor;
    $pdf = $pdf ?? false;
    $fonts = resource_path('fonts/pdf');
    // В PDF картинки — файлами с диска (dompdf наружу не ходит), на странице — обычными адресами.
    $src = fn ($m) => $pdf ? 'file://'.$m->getPath('w640') : \App\Media\MediaUrl::for($m, 'w640');
@endphp
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} — {{ $vehicle->ref ?? $vehicle->titleWithYear() }}</title>
    <style>
        :root { color-scheme: light; }
        body { margin: 0; padding: 24px; background: #f7f7f7; color: #1d1d1b; font: 14px/1.45 system-ui, -apple-system, "Segoe UI", sans-serif; }
        .act { max-width: 720px; margin: 0 auto; padding: 32px; background: #fff; }
        h1 { margin: 0 0 4px; font-size: 20px; font-weight: 500; }
        .sub { margin: 0 0 24px; color: #808080; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        th, td { padding: 6px 0; text-align: left; vertical-align: top; }
        th { width: 40%; font-weight: 400; color: #808080; }
        .block { margin-bottom: 20px; }
        .block h2 { margin: 0 0 6px; font-size: 14px; font-weight: 500; }
        .photos { table-layout: fixed; }
        .photos td { width: 33.33%; padding: 4px; text-align: center; vertical-align: top; }
        .photos img { width: 100%; height: auto; }
        .photos .cap { font-size: 11px; color: #666; margin-top: 2px; }
        .sign { margin-top: 40px; }
        .sign td { width: 50%; padding: 0 16px 0 0; vertical-align: top; }
        .sign .who { font-size: 12px; color: #444; margin-top: 4px; }
        .line { margin-top: 28px; border-top: 1px solid #1d1d1b; padding-top: 4px; color: #808080; font-size: 12px; }
        .sign .box { height: 56px; margin-bottom: -28px; }
        .sign .box img { display: block; height: 56px; width: auto; }
        .print { display: block; max-width: 720px; margin: 0 auto 16px; }
        .print button { font: inherit; padding: 10px 18px; border: 0; border-radius: 12px; background: #97bf0d; color: #fff; }
        @media print { body { padding: 0; background: #fff; } .act { max-width: none; padding: 0; } .print { display: none; } }
        @if ($pdf)
        @font-face { font-family: "Onest"; font-weight: 400; src: url("file://{{ $fonts }}/Onest-Regular.ttf"); }
        @font-face { font-family: "Onest"; font-weight: 500; src: url("file://{{ $fonts }}/Onest-SemiBold.ttf"); }
        body { padding: 0; background: #fff; font-family: "Onest", sans-serif; font-size: 12px; }
        .act { max-width: none; padding: 0; }
        @endif
    </style>
</head>
<body>
    @unless ($pdf)<div class="print"><button type="button" onclick="window.print()">Печать</button></div>@endunless
    <article class="act">
        <h1>{{ $title }}</h1>
        <p class="sub">{{ $vehicle->ref ? 'Номер '.$vehicle->ref.', ' : '' }}{{ $at?->format('d.m.Y H:i') }}</p>
        <table>
            <tr><th>Автомобиль</th><td>{{ $vehicle->titleWithYear() }}</td></tr>
            <tr><th>VIN</th><td>{{ $vehicle->vin ?? '—' }}</td></tr>
            <tr><th>Гос. номер</th><td>{{ $vehicle->plate ?? '—' }}</td></tr>
            @if ($vehicle->color)<tr><th>Цвет</th><td>{{ $vehicle->color }}</td></tr>@endif
            @if ($vehicle->category)<tr><th>Категория</th><td>{{ $vehicle->category->label() }}{{ $vehicle->oversize ? ', негабарит' : '' }}</td></tr>@endif
            <tr><th>Заказчик</th><td>{{ $vendor?->legal_name ?? $vendor?->name ?? '—' }}{{ $vendor?->inn ? ', ИНН '.$vendor->inn : '' }}</td></tr>
            @if ($vehicle->contact_name || $vehicle->contact_phone)<tr><th>Страхователь</th><td>{{ trim(($vehicle->contact_name ?? '').' '.($vehicle->contact_phone ?? '')) }}</td></tr>@endif
            <tr><th>Хранитель</th><td>{{ $company['name'] ?? 'ООО «ПРАЙМ»' }}{{ ! empty($company['inn']) ? ', ИНН '.$company['inn'] : '' }}</td></tr>
            <tr><th>Парковка</th><td>{{ implode(', ', array_filter([$vehicle->yard?->name ?? '—', $vehicle->yard?->settlement?->name, $vehicle->yard?->address, $vehicle->spot ? 'место '.$vehicle->spot : null])) }}</td></tr>
            <tr><th>Принят на хранение</th><td>{{ $vehicle->accepted_at?->format('d.m.Y H:i') ?? '—' }}</td></tr>
            @unless ($intake)
                <tr><th>Выдан</th><td>{{ $vehicle->released_at?->format('d.m.Y H:i') ?? '—' }}</td></tr>
                <tr><th>Срок хранения</th><td>{{ $vehicle->daysStored() }} дн</td></tr>
                @if ($vehicle->pickup_name)<tr><th>Получатель</th><td>{{ trim($vehicle->pickup_name.' '.($vehicle->pickup_phone ?? '')) }}</td></tr>@endif
            @endunless
            @if ($inspection)
                @if ($inspection->mileage !== null)<tr><th>Пробег</th><td>{{ number_format($inspection->mileage, 0, '', ' ') }} км</td></tr>@endif
                @if ($inspection->fuel !== null)<tr><th>Топливо</th><td>{{ $inspection->fuelLabel() }}</td></tr>@endif
                @if ($inspection->keys_count !== null)<tr><th>Ключи</th><td>{{ $inspection->keys_count }}</td></tr>@endif
                <tr><th>Документы</th><td>{{ $inspection->docs ? implode(', ', array_map(fn ($d) => \App\Park\Inspection::DOCS[$d] ?? $d, $inspection->docs)) : 'не переданы' }}</td></tr>
                @if ($inspection->equipment)<tr><th>Комплектность</th><td>{{ implode(', ', array_map(fn ($e) => \App\Park\Inspection::EQUIPMENT[$e] ?? $e, $inspection->equipment)) }}</td></tr>@endif
                @php $repair = array_filter($inspection->repairMap(), fn ($v) => $v !== null); @endphp
                @if ($repair)<tr><th>Требует ремонта</th><td>{{ implode(', ', array_map(fn ($k, $v) => \App\Park\Inspection::REPAIR[$k].' — '.($v ? 'да' : 'нет'), array_keys($repair), $repair)) }}</td></tr>@endif
            @endif
        </table>
        <div class="block">
            <h2>Повреждения при осмотре</h2>
            <p>{{ $damages || $note ? trim(implode(', ', $damages).($note ? '. '.$note : ''), '. ') : 'не обнаружены' }}</p>
        </div>
        @if ($inspection?->transit_damage)<div class="block"><h2>Повреждения при транспортировке и хранении, не соответствующие акту осмотра</h2><p>{{ $inspection->transit_damage }}</p></div>@endif
        @if ($inspection?->missing_parts)<div class="block"><h2>Отсутствующие детали и агрегаты</h2><p>{{ $inspection->missing_parts }}</p></div>@endif
        @if ($inspection?->replaced_units)<div class="block"><h2>Узлы и агрегаты со следами замены</h2><p>{{ $inspection->replaced_units }}</p></div>@endif
        @if ($shots->isNotEmpty())
            <div class="block">
                <h2>Фотографии {{ $intake ? 'при приёме' : 'при выдаче' }}</h2>
                <table class="photos">
                    @foreach ($shots->take(12)->chunk(3) as $row)
                        <tr>@foreach ($row as $m)<td width="33%"><img src="{{ $src($m) }}" alt="">@if ($slot = \App\Park\PhotoSlot::tryFrom((string) $m->getCustomProperty('slot')))<div class="cap">{{ $slot->label() }}</div>@endif</td>@endforeach</tr>
                    @endforeach
                </table>
            </div>
        @endif
        <table class="sign"><tr>
            @php $sig = $inspection?->signatureDataUrl(); @endphp
            <td><div class="box">@if ($intake && $sig)<img src="{{ $sig }}" alt="">@endif</div><div class="line">{{ $intake ? 'Сдал' : 'Выдал' }}</div>@if ($intake && $inspection?->signer_name)<div class="who">{{ $inspection->signer_name }}</div>@endif</td>
            <td><div class="box">@if (!$intake && $sig)<img src="{{ $sig }}" alt="">@endif</div><div class="line">{{ $intake ? 'Принял' : 'Получил' }}</div>@if (!$intake && $inspection?->signer_name)<div class="who">{{ $inspection->signer_name }}</div>@endif</td>
        </tr></table>
    </article>
</body>
</html>
