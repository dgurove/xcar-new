{{-- Вход в окно «Из документов» (x-mail.scan-window, одно на приложение) для предмета по адресу окна (ScanController:
     /cars/{v}/scan, /offers/{n}/scan, …/from-mail/{c}/scan). Знак — искра x-ui.spark, та же, что у VIN. Вид по месту:
     round — круглая рядом с «+ Документ» в блоке «Документы» (подсказкой «Заполнить из документов», пока в карточке
     пусто — fill, иначе «Сверить с документами»); row — строка списка там, где блока «Документы» нет (окошко
     «Наличия», разбор письма), под ней сколько есть документов и фото (files); head — «Распознать» в шапке цепочки и
     окна писем; icon — значок в полосе окошка строки и шторки документов. data-scan-subject — тот же адрес для ✨
     шторки документов: она читает открытый файл. --}}
@props(['url', 'look' => 'row', 'fill' => false, 'files' => null])
@php
    $emit = 'data-controller="emit" data-action="emit#send" data-emit-event-param="scan:open" data-emit-url-param="'.e($url).'" data-scan-subject="'.e($url).'"';
    if ($look === 'row' && $files !== null) {
        $docs = $files->reject->isPhoto()->count();
        $photos = $files->count() - $docs;
        $sub = collect([
            $docs ? $docs.' '.\App\Support\Plural::of($docs, ['документ', 'документа', 'документов']) : null,
            $photos ? $photos.' фото' : null,
        ])->filter()->implode(', ');
    }
@endphp
@if ($look === 'row')
    <button type="button" {{ $attributes->merge(['class' => 'row scan-row w-full text-left']) }} {!! $emit !!}>
        <span class="row-icon row-icon-s row-icon-soft"><x-ui.spark class="size-[18px]"/></span>
        <span class="min-w-0 flex-1">{{ $fill ? 'Заполнить из документов' : 'Сверить с документами' }}@if ($sub ?? '')<span class="row-sub">{{ $sub }}</span>@endif</span>
        <x-ui.chevron/>
    </button>
@elseif ($look === 'round')
    <button type="button" {{ $attributes->merge(['class' => 'btn btn-s btn-quiet btn-round shrink-0']) }} {!! $emit !!} aria-label="{{ $fill ? 'Заполнить из документов' : 'Сверить с документами' }}" title="{{ $fill ? 'Заполнить из документов' : 'Сверить с документами' }}"><x-ui.spark class="size-5 text-accent-text"/></button>
@elseif ($look === 'head')
    <button type="button" {{ $attributes->merge(['class' => 'btn btn-s btn-quiet case-do gap-1.5']) }} {!! $emit !!}><x-ui.spark class="size-4 text-accent-text"/>Распознать</button>
@else
    <button type="button" {{ $attributes->merge(['class' => 'peek-close']) }} {!! $emit !!} aria-label="Из документов" title="Из документов"><x-ui.spark class="size-[18px] text-accent-text"/></button>
@endif
