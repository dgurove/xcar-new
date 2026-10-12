{{-- Ход черновика, что завела почта (`AutoScan::states`): строка таблицы и плитка CRM пишут его под названием, пока
     файлы едут и документы читаются. Дочитали — строки нет, `arrive_controller` проявляет обычную. --}}
@props(['arrive'])
@php $state = $arrive['state'] ?? null; @endphp
@if ($state === 'files')
    <span class="arrive-progress"><x-ui.icon name="photo" class="size-3.5"/>Забираем фото, <span class="nums">{{ $arrive['i'] }} из {{ $arrive['n'] }}</span></span>
@elseif ($state === 'reading')
    <span class="arrive-progress spark-busy"><x-ui.spark class="size-3.5"/>Читаем документы, <span class="nums">{{ $arrive['i'] }} из {{ $arrive['n'] }}</span></span>
@elseif ($state === 'ghost')
    <span class="arrive-progress arrive-progress--wait"><x-ui.icon name="mail" class="size-3.5"/>Файлы из письма</span>
@endif
