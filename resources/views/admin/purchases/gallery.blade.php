{{-- Кадры машины закупки — пачкой, как у предложения и на парковке (23.11.2026, владелец: «приводи всё к единым модулям»; была
     развёрнутая плитка): обложка «Фото», нажатие — шторка с плиткой (добавить, глаз, поворот, удалить, порядок, главное). Стадий у
     закупки нет — пачка одна. Ответ правки (`gallery-stream`) подменяет обёртку `#gallery` целиком, открытая шторка остаётся. --}}
@php $photos = $car->photos(); $readonly = $readonly ?? false; @endphp
<div id="gallery" class="photo-packs">
    <x-ui.photo-pack :id="'purchase-'.$car->id" :photos="$photos" :when="\App\Media\PhotoBlocks::day($photos)"
        data-photos-url-value="/purchases/cars/{{ $car->id }}/media" :data-photos-readonly-value="$readonly ? 'true' : null">
        @unless ($readonly)
            <input type="file" accept="image/*,.heic" multiple class="sr-only" tabindex="-1" data-photos-target="input" data-action="change->photos#upload">
            <div hidden data-photos-target="progress" class="mb-3"><div class="mb-1 text-sm text-ink-muted" data-label></div><div class="h-1.5 overflow-hidden rounded-full bg-surface-3"><div class="h-full bg-accent transition-[width]" data-bar style="width:0"></div></div></div>
        @endunless
        <x-ui.photos :photos="$photos" :readonly="$readonly" :id="'purchase-'.$car->id" grid/>
    </x-ui.photo-pack>
</div>
