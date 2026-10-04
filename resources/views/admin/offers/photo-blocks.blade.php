{{-- Кадры предложения блоками по стадии (`Media\PhotoBlocks`, владелец 05.10.2026): «Фото от страховой» (письма, загрузка
     в CRM) и, у машины с парковки, «Фото при приёме» — не одной кучей. Блок — свой photos_controller со своей стадией:
     «Показать все», порядок и «Развернуть» — у каждого свои, просмотр листает все блоки подряд (группа). Добавляют в
     любой блок (кадр ложится его стадией); бросают файлы и вставляют из буфера — в первый. Главный кадр один на машину (звезда, `mainId`). Ответ на любое действие
     подменяет обёртку целиком (`gallery-stream`). detail — карточка строки: без корзины и заглушек.
     Подписи блоков — только когда их больше одного. --}}
@props(['offer', 'detail' => false])
@php
    $n = $offer->number;
    $root = $detail ? 'detail-photos' : 'gallery';
    // У машины с парковки — «от страховой» и, после приёма, «при приёме» всегда, оба с добавлением (машина одна:
    // кадры кладут и тут, и на парковке). Без парковки — один блок, как раньше.
    $vehicle = $offer->parkVehicle;
    $always = $vehicle ? array_values(array_filter(\App\Park\PhotoStage::cases(), fn ($s) => $s->addable($vehicle))) : [];
    $blocks = \App\Media\PhotoBlocks::of($offer->photos(), $always);
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
            @include('admin.offers.photo-upload')
            <x-ui.photos :id="$root.'-'.$stage->value" :photos="$photos" :main-id="$mainId" :pending="$first ? $pending : 0"
                :deletable="$detail ? false : fn ($m) => ! \App\Park\Sale::parkOwned($m)"/>
        </div>
    @endforeach
</div>
