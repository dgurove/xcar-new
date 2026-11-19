{{-- Договор у менеджера на сайте (19.11.2026, владелец: «пусть он изначально как docx отображается… и просто есть возможность
     скачать и в таком формате и в таком»): строкой файла со значком Word (`x-ui.file` по виду), нажатие — просмотр PDF в шторке
     документов, справа — выбор формата (`x-deal.dkp-download`). Исправленный сотрудником PDF-ом — значок PDF и только PDF. --}}
@props(['deal', 'title'])
@php
    $edited = \App\Offers\ContractEdit::has($deal);
    $original = $edited ? \App\Offers\ContractEdit::original($deal) : null;
    $isPdf = $original && \App\Offers\ContractEdit::isPdf($original);
    $base = '/deals/'.$deal->id;
@endphp
<div {{ $attributes->merge(['class' => 'file-row']) }}>
    <a href="{{ $base }}/dkp.pdf" target="_blank" class="contents" data-doc="pdf" data-doc-name="{{ $title }}" title="{{ $title }}">
        <x-ui.file-icon :name="$isPdf ? 'dkp.pdf' : 'dkp.docx'" :bytes="$original?->size"/>
        <span class="file-name"><span data-file-name>{{ $title }}</span></span>
    </a>
    <span class="file-acts"><x-deal.dkp-download :deal="$deal" :base="$base" :name="$title" :word="! $isPdf"/></span>
</div>
