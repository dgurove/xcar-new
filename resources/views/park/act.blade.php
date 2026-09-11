@php
    $at = $intake ? $vehicle->accepted_at : $vehicle->released_at;
    $title = $intake ? 'Акт приёма автомобиля на хранение' : 'Акт выдачи автомобиля с хранения';
    $damages = $vehicle->damages();
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
        .photos { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; }
        .photos img { width: 100%; aspect-ratio: 4/3; object-fit: cover; }
        .sign { display: flex; gap: 32px; margin-top: 40px; }
        .sign div { flex: 1 1 0; }
        .line { margin-top: 28px; border-top: 1px solid #1d1d1b; padding-top: 4px; color: #808080; font-size: 12px; }
        .print { display: block; max-width: 720px; margin: 0 auto 16px; }
        .print button { font: inherit; padding: 10px 18px; border: 0; border-radius: 12px; background: #97bf0d; color: #fff; }
        @media print { body { padding: 0; background: #fff; } .act { max-width: none; padding: 0; } .print { display: none; } }
    </style>
</head>
<body>
    <div class="print"><button type="button" onclick="window.print()">Печать</button></div>
    <article class="act">
        <h1>{{ $title }}</h1>
        <p class="sub">{{ $vehicle->ref ? 'Номер '.$vehicle->ref.', ' : '' }}{{ $at?->format('d.m.Y H:i') }}</p>
        <table>
            <tr><th>Автомобиль</th><td>{{ $vehicle->titleWithYear() }}</td></tr>
            <tr><th>VIN</th><td>{{ $vehicle->vin ?? '—' }}</td></tr>
            <tr><th>Гос. номер</th><td>{{ $vehicle->plate ?? '—' }}</td></tr>
            <tr><th>Цвет</th><td>{{ $vehicle->color ?? '—' }}</td></tr>
            <tr><th>Заказчик</th><td>{{ $vehicle->client?->name ?? '—' }}</td></tr>
            <tr><th>Стоянка</th><td>{{ trim(($vehicle->yard?->name ?? '—').', '.($vehicle->yard?->address ?? ''), ' ,') }}</td></tr>
            <tr><th>Принят на хранение</th><td>{{ $vehicle->accepted_at?->format('d.m.Y H:i') ?? '—' }}</td></tr>
            @unless ($intake)
                <tr><th>Выдан</th><td>{{ $vehicle->released_at?->format('d.m.Y H:i') ?? '—' }}</td></tr>
                <tr><th>Срок хранения</th><td>{{ $vehicle->daysStored() }} дн.</td></tr>
            @endunless
        </table>
        <div class="block">
            <h2>Повреждения при осмотре</h2>
            <p>{{ $damages || $vehicle->damage_note ? trim(implode(', ', $damages).($vehicle->damage_note ? '. '.$vehicle->damage_note : ''), '. ') : 'не обнаружены' }}</p>
        </div>
        @if ($intake && $vehicle->visiblePhotos()->isNotEmpty())
            <div class="block">
                <h2>Фотографии при приёме</h2>
                <div class="photos">@foreach ($vehicle->visiblePhotos()->take(9) as $m)<img src="{{ \App\Media\MediaUrl::for($m, 'w640') }}" alt="">@endforeach</div>
            </div>
        @endif
        <div class="sign">
            <div><div class="line">Сдал</div></div>
            <div><div class="line">Принял</div></div>
        </div>
    </article>
</body>
</html>
