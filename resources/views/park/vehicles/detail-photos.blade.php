{{-- Кадры в карточке строки «Наличия» — пачками по стадии, как в деле ТС (`Media\PhotoBlocks`, владелец 05.10.2026): от
     страховой, при приёме, при выдаче — не одной кучей. С 23.11.2026 — обложками (`x-ui.photo-pack`), кадры — плиткой в
     шторке. Глаз (показ в продаже) и «Скрыть все» у каждой пачки — админу; добавляют и удаляют — в деле ТС. Просмотр
     листает все пачки подряд (группа). Подпись стадии — всегда, и у единственной пачки. --}}
@php $blocks = \App\Media\PhotoBlocks::of($vehicle->photos()); $admin = auth()->user()->isAdmin(); @endphp
<div id="detail-photos" class="photo-packs">
    @foreach ($blocks as $i => ['stage' => $stage, 'photos' => $photos, 'when' => $when])
        @php $row = 'detail-photos-'.$stage->value; @endphp
        <x-ui.photo-pack :id="$row" :photos="$photos" :title="$stage->label()" :when="$when" :hide="$admin"
            data-photos-url-value="/cars/{{ $vehicle->id }}/media" data-photos-stage-value="{{ $stage->value }}" data-photos-group-value="car-{{ $vehicle->id }}" data-photos-order="{{ $i }}">
            <x-ui.photos :photos="$photos" :add="false" :deletable="false" :hide="$admin" :rotatable="auth()->user()->canManagePark()" :main="false" :id="$row" grid/>
        </x-ui.photo-pack>
    @endforeach
</div>
