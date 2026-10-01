{{-- Кнопка «✨» — открывает окно «Распознать» (x-mail.scan-window, одно на приложение) для предмета по адресу окна
     (ScanController: /cars/{v}/scan, /offers/{n}/scan, …/from-mail/{c}/scan). Вид по месту: chip — «✨ Из
     документов» у полей, которые заполняет; head — «✨ Распознать» в шапке цепочки и окна писем; icon — значок в
     полосе окошка строки. data-scan-subject — тот же адрес для ✨ шторки документов: она читает открытый файл. --}}
@props(['url', 'look' => 'chip'])
@php
    [$class, $label] = match ($look) {
        'head' => ['btn btn-s btn-quiet case-do', 'Распознать'],
        'icon' => ['peek-close', null],
        default => ['chip shrink-0', 'Из документов'],
    };
@endphp
<button type="button" {{ $attributes->merge(['class' => $class]) }} data-controller="emit" data-action="emit#send" data-emit-event-param="scan:open" data-emit-url-param="{{ $url }}" data-scan-subject="{{ $url }}" @unless ($label) aria-label="Распознать" title="Распознать" @endunless><span aria-hidden="true" @class(['mr-1' => $look === 'head'])>✨</span>{{ $label }}</button>
