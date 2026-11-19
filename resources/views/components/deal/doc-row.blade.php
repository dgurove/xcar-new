{{-- Документ сделки строкой — один вид у счетов, договоров и платёжки (16.11.2026, владелец: «почему счета по-разному выглядят в
     первом и втором блоках? у счетов и договоров иконка скачивания, а не „›“»): значок файла с весом, название и серым дата
     («Счёт поставщика от 16.11»), второй строкой — слот (сумма, откуда, состояние), справа — «Скачать» (`file#share`) и слот `acts`
     (крестик). Нажатие по строке — просмотр в шторке документов. Без файла (`download` = false) — строка ведёт по `href`. --}}
@props(['href', 'title', 'date' => null, 'name' => 'document.pdf', 'mime' => 'application/pdf', 'bytes' => null, 'download' => true, 'file' => null])
@php
    $docType = \App\Support\Docs::type($mime, $name);
    $file = $file ?? $title.($date ? ' от '.$date : '').'.'.(pathinfo($name, PATHINFO_EXTENSION) ?: 'pdf');
@endphp
<div {{ $attributes->class('row') }}>
    <a href="{{ $href }}" class="flex min-w-0 flex-1 items-center gap-3" @if ($download) target="_blank" data-doc="{{ $docType }}" data-doc-name="{{ $title }}" @endif>
        <x-ui.file-icon :name="$name" :mime="$mime" :bytes="$bytes"/>
        <span class="min-w-0 flex-1">
            <span class="block truncate">{{ $title }}@if ($date) <span class="text-ink-muted">от {{ $date }}</span>@endif</span>
            @if ($slot->isNotEmpty())<span class="row-sub">{{ $slot }}</span>@endif
        </span>
    </a>
    @if ($download || isset($acts))
        <span class="flex shrink-0 items-center">
            {{-- Свой выбор скачивания (договор: Word или PDF, `x-deal.dkp-download`) — слотом `dl` вместо одной ссылки. --}}
            @if ($download && isset($dl))
                {{ $dl }}
            @elseif ($download)<a href="{{ $href }}" download class="btn btn-ghost btn-s px-1.5 text-ink-muted" aria-label="Скачать" title="Скачать" data-controller="file" data-action="file#share" data-file-name="{{ $file }}"><x-ui.icon name="download" class="size-5"/></a>@endif
            {{ $acts ?? '' }}
        </span>
    @endif
</div>
