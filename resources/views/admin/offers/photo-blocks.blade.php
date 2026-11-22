{{-- Кадры предложения пачками по стадии (`Media\PhotoBlocks`, владелец 05.10.2026): «Фото от страховой» (письма, загрузка
     в CRM) и, у машины с парковки, «Фото при приёме» — не одной кучей. С 23.11.2026 — не лентами, а обложками рядом
     (`x-ui.photo-pack`), кадры пачки — плиткой в шторке. Пачка — свой photos_controller со своей стадией: «Скрыть все»,
     порядок и загрузка — у каждой свои, просмотр листает все пачки подряд (группа). Добавляют в любую пачку (кадр ложится
     её стадией); бросают файлы и вставляют из буфера — в первую. Главный кадр один на машину (звезда, `mainId`). Ответ на
     любое действие подменяет обёртку целиком (`gallery-stream`) морфом: открытая шторка остаётся открытой (`app.js`
     бережёт `open` у диалогов). detail — карточка строки: без корзины и заглушек. Подписи пачек — только когда их больше
     одной. --}}
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
    $pending = $detail ? 0 : \App\Offers\Jobs\ImportMigtorgLot::pending($offer) + \App\Mail\Jobs\ImportThreadFiles::pending($offer);
@endphp
<div id="{{ $root }}" class="photo-packs">
    @foreach ($blocks as $i => ['stage' => $stage, 'photos' => $photos, 'when' => $when])
        @php $first = $i === 0; $row = $root.'-'.$stage->value; @endphp
        <x-ui.photo-pack :id="$row" :photos="$photos" :title="$titled ? $stage->label() : null" :when="$when" :main-id="$mainId"
            data-photos-url-value="/offers/{{ $n }}/media" data-photos-stage-value="{{ $stage->value }}" data-photos-group-value="offer-{{ $offer->id }}"
            data-photos-order="{{ $i }}" data-photos-mark-value="true" :data-photos-any-value="$first ? 'true' : null">
            @include('admin.offers.photo-upload')
            <x-ui.photos :id="$row" :photos="$photos" :main-id="$mainId" :pending="$first ? $pending : 0" grid
                :deletable="$detail ? false : fn ($m) => ! \App\Park\Sale::parkOwned($m)"/>
        </x-ui.photo-pack>
    @endforeach
</div>
