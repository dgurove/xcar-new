{{-- Тело читалки «Завести» (Scan\Reader, reader_controller): строка хода и документы строками (admin/mail/reader-sheets) —
     сразу с сервера, дальше морфом; найденное и ход по отпечатку файла — JSON для формы и строк «Документов»; шаблон строки расхождения — её копии
     читалка кладёт в [data-reader-diffs] над полями (x-mail.reader-diffs), как «в документе иначе» дела ТС.
     keep — строки документов свои (разбор письма: это и есть документы письма), без него — только строка хода.
     open — первый PDF открывается в шторке сам. Контроллер с адресом и формой — у обёртки (блок «Документы»). --}}
@props(['subject', 'keep' => false, 'open' => false])
@php $live = \App\Mail\Scan\Reader::live($subject, $open); @endphp
<div {{ $attributes->merge(['class' => 'reader']) }} data-reader-target="body" data-state="{{ $live['state'] }}" @if ($keep) data-keep @endif>{!! $live['html'] !!}</div>
<script type="application/json" data-reader-target="values">@json($live['values'])</script>
<script type="application/json" data-reader-target="marks">@json((object) $live['marks'])</script>
<template data-reader-target="row">
    <button type="button" class="row scan-field text-left">
        <span class="scan-label" data-slot="label"></span>
        <span class="min-w-0 flex-1">
            <span class="scan-value scan-change"><span class="text-ink-muted" data-slot="now"></span><span class="text-ink-dim" aria-hidden="true" data-slot="arrow">→</span><span data-slot="doc"></span></span>
            <span class="scan-from" data-slot="from"></span>
        </span>
        <span class="shrink-0 self-center text-sm text-accent-text">Взять</span>
    </button>
</template>
