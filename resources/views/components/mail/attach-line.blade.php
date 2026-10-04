{{-- Строка «Прикрепляем фото из письма» над рядом фото, пока ImportThreadFiles переносит кадры письма в машину
     (`progressOf`): сколько из скольких и полоса. data-attach-busy — attach_controller переспрашивает ряд, пока она есть.
     Ничего не прикрепляется — пустая метка без высоты, её подменит следующий ответ. --}}
@props(['model'])
@php $p = \App\Mail\Jobs\ImportThreadFiles::progressOf($model); @endphp
<div id="attach-line" @if ($p) data-attach-busy class="reader-head" @endif>
    @if ($p)
        <x-ui.spark class="size-4 shrink-0 text-accent-text spark-busy"/>
        <span class="shrink-0">Прикрепляем фото из письма</span>
        @if ($p['n'] ?? null)
            <span class="scan-bar mt-0 min-w-8 flex-1" role="progressbar" aria-valuemin="0" aria-valuemax="{{ $p['n'] }}" aria-valuenow="{{ $p['i'] }}"><span style="width: {{ max(4, round(100 * $p['i'] / max(1, $p['n']))) }}%"></span></span>
            <span class="nums shrink-0">{{ $p['i'] }} из {{ $p['n'] }}</span>
        @endif
    @endif
</div>
