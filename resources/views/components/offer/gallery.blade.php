{{-- Кадры оффера: лента со snap, стрелки, счётчик, полноэкранный просмотр, миниатюры 4/6 в ряд
     (:thumbs="false" — миниатюры только от lg). strip — узкая лента кадров в окошке
     строки таблицы: несколько в ряд, без стрелок, тап — во весь экран. --}}
@props(['photos', 'alt' => '', 'thumbs' => true, 'strip' => false])
@if ($photos->isEmpty())
    @if ($strip)<div class="peek-strip-empty"><x-ui.car-blank/></div>@else<div class="flex aspect-[4/3] items-center justify-center rounded-(--radius-xl) bg-surface-3 text-ink-dim">Фотографий нет</div>@endif
@elseif ($strip)
    <div class="peek-strip" data-controller="gallery" data-gallery-target="strip">
        @foreach ($photos as $i => $media)
            <a href="{{ \App\Media\MediaUrl::for($media) }}" class="peek-strip-frame" data-action="click->gallery#open" data-index="{{ $i }}">
                <x-offer.photo :media="$media" sizes="108px" :eager="$i < 4" class="size-full object-cover"/>
            </a>
        @endforeach
    </div>
@else
    <div data-controller="gallery" data-action="keydown@window->gallery#key">
        <div class="relative overflow-hidden rounded-(--radius-xl) bg-surface-3">
            <div class="flex snap-x snap-mandatory overflow-x-auto" data-gallery-target="strip" style="scrollbar-width:none">
                @foreach ($photos as $i => $media)
                    <a href="{{ \App\Media\MediaUrl::for($media) }}" class="aspect-[4/3] w-full shrink-0 snap-center cursor-zoom-in" data-action="click->gallery#open" data-index="{{ $i }}">
                        <x-offer.photo :media="$media" sizes="(min-width: 1024px) 60vw, 100vw" :eager="$i === 0" class="size-full object-cover"/>
                    </a>
                @endforeach
            </div>
            @if ($photos->count() > 1)
                <button type="button" class="absolute left-2 top-1/2 flex size-11 -translate-y-1/2 items-center justify-center rounded-full bg-surface/80 backdrop-blur hover:bg-surface" data-action="gallery#prev" aria-label="Предыдущее фото"><x-ui.icon name="chevron-left" class="size-5"/></button>
                <button type="button" class="absolute right-2 top-1/2 flex size-11 -translate-y-1/2 items-center justify-center rounded-full bg-surface/80 backdrop-blur hover:bg-surface" data-action="gallery#next" aria-label="Следующее фото"><x-ui.icon name="chevron-right" class="size-5"/></button>
                <span class="nums absolute bottom-2 right-2 rounded-(--radius-s) bg-surface/80 px-2 py-1 text-sm font-normal backdrop-blur" data-gallery-target="counter">1 / {{ $photos->count() }}</span>
            @endif
            <button type="button" class="absolute right-2 top-2 flex size-11 items-center justify-center rounded-full bg-surface/80 backdrop-blur hover:bg-surface" data-action="gallery#open" aria-label="Открыть на весь экран"><x-ui.icon name="expand" class="size-4"/></button>
        </div>
        @if ($photos->count() > 1)
            <div class="mt-3 {{ $thumbs ? 'grid' : 'hidden lg:grid' }} grid-cols-4 gap-2 sm:grid-cols-6">
                @foreach ($photos as $i => $media)
                    <button type="button" class="overflow-hidden rounded-(--radius-s) ring-accent {{ $i === 0 ? 'ring-2' : 'opacity-70 hover:opacity-100' }}" data-gallery-target="thumb" data-action="gallery#to" data-gallery-index-param="{{ $i }}">
                        <img src="{{ \App\Media\MediaUrl::for($media, 'thumb') }}" alt="" width="400" height="300" loading="lazy" class="aspect-[4/3] w-full object-cover">
                    </button>
                @endforeach
            </div>
        @endif
    </div>
@endif
