{{-- Вид ДКП по ГОСТ Р 7.0.97-2016 (19.10.2026, владелец: «проверь по ГОСТу»): поля не меньше 20 / 10 / 20 / 20 мм
     , Times 12 pt, одинарный интервал, абзацный отступ 1,25 см, текст по ширине, номера
     страниц со второй — посередине верхнего поля, расшифровка подписи «А. В. Кузнецов». В PDF — Liberation Serif
     (метрики Times, свободная), на странице печати и в шторке документов (`?embed=1`) — системный Times. Пустое —
     линией `.blank` (`DkpDocument::v`), а не подчёркиваниями: те растягивали строку дырами. --}}
@php
    $pdf ??= false;
    $embed = ! $pdf && request()->boolean('embed');
    $fonts = resource_path('fonts/pdf');
    $margin = '20mm 10mm 20mm 20mm';
@endphp
<style>
    @if ($pdf)
    @font-face { font-family: "Liberation Serif"; font-weight: 400; font-style: normal; src: url("file://{{ $fonts }}/LiberationSerif-Regular.ttf"); }
    @font-face { font-family: "Liberation Serif"; font-weight: 700; font-style: normal; src: url("file://{{ $fonts }}/LiberationSerif-Bold.ttf"); }
    @font-face { font-family: "Liberation Serif"; font-weight: 400; font-style: italic; src: url("file://{{ $fonts }}/LiberationSerif-Italic.ttf"); }
    @page { size: A4; margin: {{ $margin }}; }
    .pagenum { position: fixed; top: -12mm; left: 0; right: 0; text-align: center; }
    .pagenum:after { content: counter(page); }
    .mask { position: absolute; top: -14mm; left: 0; right: 0; height: 9mm; background: #fff; }
    @endif
    body { font-family: "Liberation Serif", "Times New Roman", Times, serif; font-size: 12pt; line-height: 1.15; color: #000; margin: 0; background: #e9e9e9; padding: 24px 12px; }
    .sheet { position: relative; max-width: 210mm; box-sizing: border-box; margin: 0 auto 24px; background: #fff; padding: {{ $margin }}; }
    h1 { font-size: 12pt; font-weight: 700; text-align: center; margin: 0; }
    .sub { text-align: center; font-weight: 700; margin: 0 0 8pt; }
    p { margin: 0 0 1pt; text-align: justify; text-indent: 12.5mm; }
    p.flat { text-indent: 0; }
    .head { width: 100%; border-collapse: collapse; margin: 4pt 0 8pt; }
    .head td { padding: 0; }
    .head td + td { text-align: right; }
    .sec { font-weight: 700; text-align: center; margin: 6pt 0 2pt; }
    .blank { display: inline-block; border-bottom: 0.5pt solid #000; height: 1em; vertical-align: -0.2em; }
    .car { width: 100%; border-collapse: collapse; margin: 3pt 0 5pt; }
    .car td { border: 0.5pt solid #000; padding: 2pt 5pt; vertical-align: top; }
    .car td:first-child { width: 42%; }
    .car .blank { width: 100% !important; }
    .facts { border-collapse: collapse; margin: 1pt 0 3pt 12.5mm; }
    .facts td { padding: 0 8pt 0 0; vertical-align: top; }
    .keep { page-break-inside: avoid; }
    .nb { white-space: nowrap; }
    .parties { width: 100%; border-collapse: collapse; margin-top: 6pt; }
    .parties td { width: 50%; vertical-align: top; padding: 0 10pt 0 0; }
    .parties td + td { padding: 0 0 0 10pt; }
    .parties b { display: block; margin-bottom: 2pt; }
    .sign { margin-top: 12pt; }
    .sign .blank { width: 40mm; }
    .cap { font-size: 9pt; }
    .act { page-break-before: always; height: 0; }
    .draft { position: fixed; top: -15mm; left: -20mm; right: -10mm; bottom: -15mm; overflow: hidden; }
    .draft-row { position: absolute; white-space: nowrap; font-size: 26pt; font-weight: 700; letter-spacing: 3pt; color: #ededed; }
    .print { position: fixed; top: 12px; right: 12px; font-family: system-ui, sans-serif; }
    .print button { font: inherit; font-size: 14px; padding: 8px 16px; border: 0; border-radius: 12px; background: #97bf0d; color: #fff; }
    @if ($embed)
    body { background: #fff; padding: 0; }
    .sheet { max-width: none; margin: 0; padding: 16px 14px; }
    p { text-indent: 1.5em; }
    @endif
    @if ($pdf)
    body { background: #fff; padding: 0; }
    .sheet { max-width: none; margin: 0; padding: 0; background: transparent; }
    @endif
    @media print { body { background: #fff; padding: 0; } .sheet { margin: 0; padding: 0; max-width: none; } .print { display: none; } @page { margin: {{ $margin }}; } }
</style>
