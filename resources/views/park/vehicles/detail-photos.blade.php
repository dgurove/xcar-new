{{-- Кадры в карточке строки «Наличия» — блоками по стадии, как в деле ТС (`Media\PhotoBlocks`, владелец 05.10.2026):
     от страховой, при приёме, при выдаче — не одной кучей. Глаз (показ в продаже) и «Показать все» у каждого блока;
     добавляют и удаляют — в деле ТС. Просмотр листает все блоки подряд (группа). Подписи — когда блоков больше одного. --}}
@php $blocks = \App\Media\PhotoBlocks::of($vehicle->photos()); @endphp
<div id="detail-photos" class="photo-blocks">
    @foreach ($blocks as $i => ['stage' => $stage, 'photos' => $photos])
        <div class="photo-block" data-controller="photos" data-photos-url-value="/cars/{{ $vehicle->id }}/media" data-photos-stage-value="{{ $stage->value }}" data-photos-group-value="car-{{ $vehicle->id }}" data-photos-order="{{ $i }}">
            <div class="photos-over">@if (count($blocks) > 1)<span class="photo-block-title">{{ $stage->label() }}</span>@endif<x-ui.photos-expand/><x-ui.photos-all/></div>
            <x-ui.photos :photos="$photos" :add="false" :deletable="false" :main="false" :id="'detail-photos-'.$stage->value"/>
        </div>
    @endforeach
</div>
