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
    {{-- Тем же столбиком, что `x-park.doc-differences` (06.11.2026): поле, было → стало, откуда, ниже кнопки. --}}
    <div class="row doc-diff-row">
        <span class="min-w-0 flex-1">
            <span class="block text-sm text-ink-muted" data-slot="label"></span>
            <span class="doc-diff-change"><s class="text-ink-dim" data-slot="now"></s><span class="hidden" data-slot="arrow"></span><span class="font-medium" data-slot="doc"></span></span>
            <span class="block text-sm text-ink-dim" data-slot="from"></span>
            <span class="mt-2 flex gap-2">
                <button type="button" class="btn btn-s btn-quiet max-w-48 flex-1" data-act="reject">Отклонить</button>
                <button type="button" class="btn btn-s btn-accent max-w-48 flex-1" data-act="take">Взять</button>
            </span>
        </span>
    </div>
</template>
