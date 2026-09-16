{{-- Выгрузка закупки в PDF: альбомный A4 по бренду — Onest, лайм, тонкие линии.
     Рисует dompdf: без flex и grid, шрифты и логотип файлами из resources/fonts/pdf и public/images.
     Закупка бывает на сотни машин: таблица режется на страницы (память dompdf растёт с
     числом ячеек одной таблицы), а матрица с менеджерами — ещё и на наборы по 6 колонок
     людей, как печать широкой таблицы в Excel. --}}
@php
    $fmt = fn ($v) => is_int($v) || is_float($v) ? ($v >= 10000 ? \App\Support\Money::nums($v) : $v) : $v;
    $fonts = resource_path('fonts/pdf');
    $logo = public_path('images/xcar.svg');
    $perSet = 6;
    $parts = [];
    foreach ($tables as $t) {
        $rows = $t['rows'];
        $fixed = array_search('Минимальная', $t['head'], true);
        if ($fixed === false) {
            $parts[] = ['name' => $t['name'], 'head' => $t['head'], 'rows' => $rows, 'dense' => false];
            continue;
        }
        // Матрица: машина одной ячейкой (марка, модель, год) и тип с городом — иначе колонки людей не влезают.
        $head = ['ДЛ', 'Машина', 'Тип, город', 'Размещение', 'Наша цена', 'Максимальная', 'Минимальная'];
        $car = fn ($r) => [$r[0], trim($r[1].' '.$r[2]).($r[3] ? ', '.$r[3] : ''), $r[4].($r[5] ? ', '.$r[5] : ''), $r[6], $r[7], $r[8], $r[9]];
        $people = array_slice(array_keys($t['head']), $fixed + 1);
        foreach (array_chunk($people, $perSet) ?: [[]] as $group) {
            $parts[] = [
                'name' => $t['name'].(count($people) > $perSet ? ', менеджеры '.($group[0] - $fixed).'–'.(end($group) - $fixed) : ''),
                'head' => [...$head, ...array_map(fn ($i) => $t['head'][$i], $group)],
                'rows' => array_map(fn ($r) => [...$car($r), ...array_map(fn ($i) => $r[$i] ?? '', $group)], $rows),
                'dense' => true,
            ];
        }
    }
@endphp
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<style>
    @font-face { font-family: "Onest"; font-weight: 400; src: url("file://{{ $fonts }}/Onest-Regular.ttf"); }
    @font-face { font-family: "Onest"; font-weight: 600; src: url("file://{{ $fonts }}/Onest-SemiBold.ttf"); }
    @font-face { font-family: "Onest"; font-weight: 700; src: url("file://{{ $fonts }}/Onest-Bold.ttf"); }
    @page { margin: 14mm 12mm 16mm; }
    body { font-family: "Onest", "DejaVu Sans", sans-serif; font-size: 8pt; line-height: 1.3; color: #1d1d1b; }
    .head { width: 100%; border-bottom: 1.2pt solid #97bf0d; padding-bottom: 4mm; margin-bottom: 8mm; }
    .head td { vertical-align: bottom; padding: 0; }
    .head img { height: 9mm; }
    .head .title { text-align: right; font-size: 15pt; font-weight: 700; line-height: 1.15; }
    .head .sub { text-align: right; color: #808080; font-size: 8.5pt; margin-top: 1mm; }
    section { page-break-before: always; }
    section:first-of-type { page-break-before: auto; }
    h2 { font-size: 12pt; font-weight: 600; margin: 0 0 4mm; }
    table.t { border-collapse: collapse; width: 100%; }
    table.t thead { display: table-header-group; }
    table.t th { background: #f7f7f7; color: #808080; font-weight: 600; text-align: left; padding: 1.8mm 2.2mm; border-bottom: 0.4pt solid #d0d0d0; white-space: nowrap; }
    table.t th:first-child { border-radius: 2mm 0 0 0; }
    table.t th:last-child { border-radius: 0 2mm 0 0; }
    table.t td { padding: 1.3mm 2.2mm; border-bottom: 0.3pt solid #ececec; vertical-align: top; }
    table.t.dense { font-size: 7.5pt; }
    table.t.dense th, table.t.dense td { padding: 1.1mm 1.6mm; white-space: nowrap; }
    table.t th.n, table.t td.n { text-align: right; white-space: nowrap; }
    table.t th.hi { color: #669709; }
    table.t td.hi { background: #f0f7d8; font-weight: 600; }
    table.t tr { page-break-inside: avoid; }
    .empty { color: #808080; }
</style>
</head>
<body>
<table class="head">
    <tr>
        <td><img src="file://{{ $logo }}" alt="XCar"></td>
        <td>
            <div class="title">{{ $purchase->title ?: $purchase->publicTitle() }}</div>
            <div class="sub">Предложения менеджеров, {{ now()->translatedFormat('j F Y') }}</div>
        </td>
    </tr>
</table>
@foreach ($parts as $t)
    @php
        $hi = array_keys(array_intersect($t['head'], ['Максимальная', 'Минимальная']));
        $numeric = array_map(fn ($i) => (bool) array_filter($t['rows'], fn ($r) => isset($r[$i]) && (is_int($r[$i]) || is_float($r[$i]))), array_keys($t['head']));
        // Страница за раз: первая короче (заголовок документа), дальше по полной — строка ≈ 7 мм плотная, 8 мм обычная.
        $per = $t['dense'] ? 24 : 21;
        $first = $loop->first ? ($t['dense'] ? 16 : 12) : $per - 2;
        $chunks = [array_slice($t['rows'], 0, $first), ...array_chunk(array_slice($t['rows'], $first), $per)];
    @endphp
    <section>
        <h2>{{ $t['name'] }}</h2>
        @foreach ($chunks as $chunk)
            <table @class(['t', 'dense' => $t['dense']]) @if (! $loop->last) style="page-break-after: always" @endif>
                <thead><tr>@foreach ($t['head'] as $i => $h)<th @class(['n' => $numeric[$i], 'hi' => in_array($i, $hi, true)])>{{ $h }}</th>@endforeach</tr></thead>
                <tbody>
                @forelse ($chunk as $row)
                    <tr>@foreach ($t['head'] as $i => $h)<td @class(['n' => $numeric[$i], 'hi' => in_array($i, $hi, true) && ($row[$i] ?? '') !== ''])>{{ $fmt($row[$i] ?? '') }}</td>@endforeach</tr>
                @empty
                    <tr><td colspan="{{ count($t['head']) }}" class="empty">Пусто</td></tr>
                @endforelse
                </tbody>
            </table>
        @endforeach
    </section>
@endforeach
</body>
</html>
