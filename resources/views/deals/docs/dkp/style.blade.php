{{-- Общий вид ДКП (19.10.2026): лист A4 с засечками, как шаблоны владельца (Times New Roman). В PDF — Liberation Serif
     (метрики Times, свободная), на странице печати и в шторке документов (`?embed=1`) — системный Times. $size — кегль
     шаблона (у собственника 10 pt, у юрлица 12 pt), $margin — поля листа. --}}
@php
    $pdf ??= false;
    $embed = ! $pdf && request()->boolean('embed');
    $fonts = resource_path('fonts/pdf');
@endphp
<style>
    @if ($pdf)
    @font-face { font-family: "Liberation Serif"; font-weight: 400; font-style: normal; src: url("file://{{ $fonts }}/LiberationSerif-Regular.ttf"); }
    @font-face { font-family: "Liberation Serif"; font-weight: 700; font-style: normal; src: url("file://{{ $fonts }}/LiberationSerif-Bold.ttf"); }
    @font-face { font-family: "Liberation Serif"; font-weight: 400; font-style: italic; src: url("file://{{ $fonts }}/LiberationSerif-Italic.ttf"); }
    @page { size: A4; margin: {{ $margin }}; }
    @endif
    body { font-family: "Liberation Serif", "Times New Roman", Times, serif; font-size: {{ $size }}pt; line-height: {{ $leading ?? 1.2 }}; color: #000; margin: 0; background: #e9e9e9; padding: 24px 12px; }
    .sheet { max-width: 210mm; box-sizing: border-box; margin: 0 auto 24px; background: #fff; padding: {{ $margin }}; }
    h1 { font-size: {{ $size }}pt; font-weight: 700; text-align: center; margin: 0; }
    .sub { text-align: center; font-weight: 700; margin: 0 0 10pt; }
    p { margin: 0 0 3pt; text-align: justify; }
    .head { width: 100%; border-collapse: collapse; margin: 6pt 0 10pt; }
    .head td { padding: 0; }
    .head td + td { text-align: right; }
    .sec { font-weight: 700; text-align: center; margin: 8pt 0 3pt; }
    .num { white-space: nowrap; }
    .car { width: 100%; border-collapse: collapse; margin: 4pt 0 6pt; }
    .car td { border: 0.5pt solid #000; padding: 2pt 5pt; vertical-align: top; }
    .car td:first-child { width: 42%; }
    .facts { border-collapse: collapse; margin: 2pt 0 6pt; }
    .facts td { padding: 0 8pt 1pt 0; vertical-align: top; }
    .parties { width: 100%; border-collapse: collapse; margin-top: 8pt; }
    .parties td { width: 50%; vertical-align: top; padding: 0 10pt 0 0; }
    .parties td + td { padding: 0 0 0 10pt; }
    .parties b { display: block; }
    .parties div { margin-bottom: 1pt; }
    .sign { margin-top: 14pt; }
    .mp { margin-top: 4pt; }
    .act { page-break-before: always; height: 0; }
    .print { position: fixed; top: 12px; right: 12px; font-family: system-ui, sans-serif; }
    .print button { font: inherit; font-size: 14px; padding: 8px 16px; border: 0; border-radius: 12px; background: #97bf0d; color: #fff; }
    @if ($embed)
    body { background: #fff; padding: 0; }
    .sheet { max-width: none; margin: 0; padding: 16px 14px; }
    @endif
    @if ($pdf)
    body { background: #fff; padding: 0; }
    .sheet { max-width: none; margin: 0; padding: 0; }
    @endif
    @media print { body { background: #fff; padding: 0; } .sheet { margin: 0; padding: 0; max-width: none; } .print { display: none; } }
</style>
