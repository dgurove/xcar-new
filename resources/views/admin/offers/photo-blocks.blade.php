{{-- Кадры предложения блоками по стадии (`Media\PhotoBlocks`, владелец 05.10.2026): «Фото от страховой» (письма, загрузка
     в CRM) и, у машины с парковки, «Фото при приёме» — не одной кучей. Блок — свой photos_controller со своей стадией:
     «Показать все», порядок и «Развернуть» — у каждого свои, просмотр листает все блоки подряд (группа). Добавляют, бросают
     файлы и вставляют из буфера — в первый блок. Главный кадр один на машину (звезда, `mainId`). Ответ на любое действие
     подменяет обёртку целиком (`gallery-stream`). detail — карточка строки: без корзины и заглушек.
     Подписи блоков — только когда их больше одного. --}}
@props(['offer', 'detail' => false])
@php
    $n = $offer->number;
    $root = $detail ? 'detail-photos' : 'gallery';
    $blocks = \App\Media\PhotoBlocks::of($offer->photos());
    $titled = count($blocks) > 1;
    $mainId = $offer->mainPhoto()?->id;
    $pending = $detail ? 0 : \App\Offers\Jobs\ImportMigtorgLot::pending($offer->id) + \App\Mail\Jobs\ImportThreadFiles::pending($offer);
@endphp
<div id="{{ $root }}" class="photo-blocks">
    @foreach ($blocks as $i => ['stage' => $stage, 'photos' => $photos])
        @php $first = $i === 0; @endphp
        <div class="photo-block" data-controller="photos" data-photos-url-value="/offers/{{ $n }}/media" data-photos-stage-value="{{ $stage->value }}" data-photos-group-value="offer-{{ $offer->id }}" data-photos-order="{{ $i }}" data-photos-mark-value="true" @if ($first) data-photos-any-value="true" @endif>
            {{-- Над лентой, а не под ней: развёрнутая плиткой лента уносила бы кнопки вниз. --}}
            <div class="photos-over">@if ($titled)<span class="photo-block-title">{{ $stage->label() }}</span>@endif<x-ui.photos-expand/><x-ui.photos-all/></div>
            @if ($first)@include('admin.offers.photo-upload')@endif
            <x-ui.photos :id="$root.'-'.$stage->value" :photos="$photos" :main-id="$mainId" :add="$first" :pending="$first ? $pending : 0"
                :deletable="$detail ? false : fn ($m) => ! \App\Park\Sale::parkOwned($m)"/>
        </div>
    @endforeach
</div>
