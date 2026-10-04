{{-- Строка «Прикрепляем фото» над рядом фото, пока кадры едут в машину: из письма (ImportThreadFiles, `progressOf`) или с
     Мигторга (ImportMigtorgLot, `progress`) — сколько из скольких и полоса; заглушки в ряду — те же. data-attach-busy —
     attach_controller переспрашивает ряд, пока она есть. Ничего не едет — пустая метка без высоты, её подменит ответ. --}}
@props(['model'])
@php
    $mail = \App\Mail\Jobs\ImportThreadFiles::progressOf($model);
    $migtorg = ! $mail && $model instanceof \App\Offers\Offer ? \App\Offers\Jobs\ImportMigtorgLot::progress($model->id) : null;
    $p = $mail ?? $migtorg;
@endphp
<div id="attach-line" @if ($p) data-attach-busy class="reader-head" @endif>
    @if ($p)
        <x-ui.spark class="size-4 shrink-0 text-accent-text spark-busy"/>
        <span class="shrink-0">{{ $migtorg ? 'Прикрепляем фото с Мигторга' : 'Прикрепляем фото из письма' }}</span>
        @if ($p['n'] ?? null)
            <span class="scan-bar mt-0 min-w-8 flex-1" role="progressbar" aria-valuemin="0" aria-valuemax="{{ $p['n'] }}" aria-valuenow="{{ $p['i'] }}"><span style="width: {{ max(4, round(100 * $p['i'] / max(1, $p['n']))) }}%"></span></span>
            <span class="nums shrink-0">{{ $p['i'] }} из {{ $p['n'] }}</span>
        @endif
    @endif
</div>
