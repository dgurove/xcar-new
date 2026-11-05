{{-- Акт ТС одной кнопкой-иконкой (`IntakeActs`): первым — акт приёма, следом выдача и наш отчёт — скрытыми ссылками той же
     строки, они вкладками в шторке документов. Акта по имени нет — та же кнопка тусклой: внутри прочие документы машины,
     акт среди них. Нет ничего — пусто. --}}
@props(['acts'])
@php
    $named = [...$acts['intake'], ...$acts['release']];
    $docs = $named ?: $acts['other'];
@endphp
@foreach ($docs as $n => $d)
    <x-ui.doc :doc="$d" :class="$n ? 'hidden' : 'doc-act-btn'.($named ? '' : ' is-other')"><x-ui.icon name="file" class="size-[18px]"/></x-ui.doc>
@endforeach
