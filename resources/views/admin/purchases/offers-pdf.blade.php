{{-- Предложения менеджеров в PDF: таблица на лист, альбомный A4. Рисует dompdf, шрифт DejaVu (кириллица). --}}
@php $fmt = fn ($v) => is_int($v) || is_float($v) ? ($v >= 10000 ? number_format($v, 0, '', ' ') : $v) : $v; @endphp
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<style>
    @page { margin: 12mm 10mm; }
    body { font-family: "DejaVu Sans", sans-serif; font-size: 8pt; color: #111; }
    h1 { font-size: 13pt; font-weight: bold; margin: 0 0 2mm; }
    h2 { font-size: 10pt; font-weight: bold; margin: 0 0 3mm; }
    .date { color: #666; margin: 0 0 6mm; }
    section { page-break-before: always; }
    section:first-of-type { page-break-before: auto; }
    table { border-collapse: collapse; width: 100%; }
    thead { display: table-header-group; }
    th, td { border: 0.3pt solid #999; padding: 1.2mm 1.6mm; text-align: left; vertical-align: top; }
    th { background: #eee; font-weight: bold; }
    td.n { text-align: right; white-space: nowrap; }
</style>
</head>
<body>
<h1>{{ $purchase->title ?: $purchase->publicTitle() }}</h1>
<p class="date">{{ now()->translatedFormat('j F Y, H:i') }}</p>
@foreach ($tables as $t)
    <section>
        <h2>{{ $t['name'] }}</h2>
        <table>
            <thead><tr>@foreach ($t['head'] as $h)<th>{{ $h }}</th>@endforeach</tr></thead>
            <tbody>
            @foreach ($t['rows'] as $row)
                <tr>@foreach ($row as $v)<td @class(['n' => is_int($v) || is_float($v)])>{{ $fmt($v) }}</td>@endforeach @if (! $row)<td colspan="{{ count($t['head']) }}">&nbsp;</td>@endif</tr>
            @endforeach
            </tbody>
        </table>
    </section>
@endforeach
</body>
</html>
