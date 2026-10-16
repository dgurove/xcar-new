{{-- Кадры плитки: листаются (frames), точки и стрелки — от двух кадров; без фото — заглушка-ссылка. Одна разметка у плиток
     предложения, CRM, парковки, закупки и гаража. link — атрибуты ссылок как есть (карточка рядом со списком), eager — первый кадр сразу. --}}
@props(['photos', 'href', 'sizes' => null, 'eager' => false, 'link' => null])
@php
    $sizes ??= \App\Support\ListView::sizes(\App\Support\ListView::fromRequest(request()));
    $extra = $link === null ? '' : ' '.$link;
@endphp
@if ($photos->isNotEmpty())
    <div class="card-media" data-controller="frames" data-action="cards:tick@window->frames#next cards:stop@window->frames#stop">
        <a href="{{ $href }}"{!! $extra !!} class="card-strip" data-frames-target="strip" data-action="frames#click touchstart->frames#touch:passive">
            @foreach ($photos as $i => $frame)
                <x-offer.photo :media="$frame" :sizes="$sizes" :eager="$eager && $i === 0" data-frames-target="frame"/>
            @endforeach
        </a>
        @if ($photos->count() > 1)
            <div class="card-frames">
                <div class="pointer-events-none absolute inset-x-0 bottom-3 flex justify-center gap-1">
                    @foreach ($photos as $i => $frame)<span class="card-dot{{ $i === 0 ? ' card-dot--on' : '' }}" data-frames-target="dot"></span>@endforeach
                </div>
                <button type="button" class="card-arrow left-2 hidden sm:flex" data-action="frames#prev" aria-label="Предыдущее фото"><x-ui.icon name="chevron-left" class="size-[18px]"/></button>
                <button type="button" class="card-arrow right-2 hidden sm:flex" data-action="frames#manual" aria-label="Следующее фото"><x-ui.icon name="chevron-right" class="size-[18px]"/></button>
            </div>
        @endif
    </div>
@else
    <a href="{{ $href }}"{!! $extra !!} class="card-media card-media--blank" tabindex="-1"><x-ui.car-blank/></a>
@endif
