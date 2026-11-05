{{-- Акт ТС тихой меткой «[значок] Акт» (`IntakeActs`, той же разметки, что файлы в списке «Счетов и актов»): первым — акт
     приёма, следом выдача и наш отчёт — скрытыми ссылками той же строки, они вкладками в шторке документов. Акта по имени
     нет — «Док.» тусклее: внутри прочие документы машины, акт среди них. Нет ничего — пусто. --}}
@props(['acts'])
@php
    $named = [...$acts['intake'], ...$acts['release']];
    $docs = $named ?: $acts['other'];
@endphp
@foreach ($docs as $n => $d)
    @if ($n)
        <x-ui.doc :doc="$d" class="hidden"/>
    @else
        <x-ui.doc :doc="$d" :class="'doc-row-file'.($named ? '' : ' is-other')"><x-ui.file-icon :name="$d['name']"/>{{ $named ? 'Акт' : 'Док.' }}</x-ui.doc>
    @endif
@endforeach
