{{-- Ход черновика, что завела почта (`AutoScan::states`): строка таблицы и плитка CRM пишут его под названием, пока
     файлы едут и документы читаются. Значок, что делаем, и полоска хода с «3 из 23» — полоска растёт плавно: морф
     меняет только её ширину. Ждём очереди — серым, без полоски. Дочитали — строки нет, `arrive_controller` проявляет
     обычную. --}}
@props(['arrive'])
@php
    $state = $arrive['state'] ?? null;
    $i = (int) ($arrive['i'] ?? 0);
    $n = max(1, (int) ($arrive['n'] ?? 1));
    // Полоска — сколько уже готово: «3 из 23» значит третье в работе, готово два.
    $done = round(100 * max(0, $i - 1) / $n);
@endphp
@if ($state === 'files' || $state === 'reading')
    <span @class(['arrive-progress', 'spark-busy' => $state === 'reading'])>
        @if ($state === 'reading')<x-ui.spark class="size-3.5"/>@else<x-ui.icon name="photo" class="size-3.5"/>@endif
        <span>{{ $state === 'reading' ? 'Читаем документы' : 'Забираем фото' }}</span>
        <span class="arrive-meter" aria-hidden="true"><span style="width: {{ $done }}%"></span></span>
        <span class="nums">{{ $i }} из {{ $n }}</span>
    </span>
@elseif ($state === 'ghost')
    <span class="arrive-progress arrive-progress--wait"><x-ui.icon :name="($arrive['what'] ?? null) === 'docs' ? 'file' : 'clock'" class="size-3.5"/>{{ ($arrive['what'] ?? null) === 'docs' ? 'Документы в очереди' : 'Файлы в очереди' }}</span>
@endif
