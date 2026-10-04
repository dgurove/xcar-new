{{-- Строки расхождений читалки «Завести» над полями машины: «в форме → в документе» и «Взять» (reader_controller
     собирает их из шаблона x-mail.reader-body). Пусто — группы не видно. --}}
@props(['form'])
<div {{ $attributes->merge(['class' => 'list doc-diff']) }} data-reader-diffs="{{ $form }}" hidden></div>
