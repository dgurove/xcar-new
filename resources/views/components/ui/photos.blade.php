{{-- Кадры плиткой: «добавить» первой клеткой, дальше квадраты; на кадре действия глаз, поворот, корзина, скрытый —
     серый, главное — первый видимый (лаймовая звезда-метка; сменить — в просмотре или долгим нажатием по кадру), порядок перетаскиванием, клик по
     кадру — просмотрщик, файлы можно бросить на карточку. Живёт внутри
     data-controller="photos"; turbo-stream подменяет ряд целиком.
     readonly — только смотреть (кадры из письма рядом с приёмом): без плитки «добавить» и без действий; с hide — остаётся глаз.
     Всегда плиткой (сетка квадратов): с 23.11.2026 кадры везде в шторке пачки (`x-ui.photo-pack`), лент нет; `grid` — прежнее
     имя, принимается и ничего не меняет.
     rotatable=false — без поворота (помощник парковки).
     deletable=false — без корзины (удаляют только в полном редакторе); замыкание — решает по кадру.
     pending — сколько заглушек «качается» в конце ряда. add=false — без плитки «добавить» (карточка строки парковки).
     Кнопка «Показать все» / «Скрыть все» — отдельно, x-ui.photos-all, там, где ей место по вёрстке.
     mainId — главный кадр всей машины, когда кадры разложены пачками по стадиям (PhotoBlocks): звезда у него, в чужой пачке
     звезды нет. main=false — главного нет вовсе (стоянка, логистика): ни звезды, ни «Главное» в просмотре.
     hideBy — что значит глаз: по умолчанию «скрыт с сайта» (`Media\Hidden`), в логистике — «не видит логист / исполнитель»
     (`Logistics\FileAccess`, замыкание «скрыт ли кадр»); eyeTitle — подсказка глаза («Логисту»).
     Цикл кадров — без отступов: у машины бывает 240 кадров, отступы шаблона давали ~70 КБ на карточку. --}}
@props(['photos', 'hide' => true, 'hideBy' => null, 'eyeTitle' => null, 'main' => true, 'mainId' => null, 'readonly' => false, 'id' => 'gallery', 'grid' => false, 'deletable' => true, 'rotatable' => true, 'pending' => 0, 'add' => true, 'addLabel' => 'Добавить фото'])
<div id="{{ $id }}" class="photo-grid" @if ($main && ! $readonly) data-main @endif>
    @if (! $readonly && $add)<button type="button" class="photo-add" data-action="photos#pick" aria-label="{{ $addLabel }}" title="{{ $addLabel }}"><x-ui.icon name="camera" class="size-6"/></button>@endif
    <div class="contents" data-photos-target="grid">
    @php $mainShown = false; @endphp
@foreach ($photos as $media)
@php $hidden = $hide && ($hideBy ? $hideBy($media) : \App\Media\Hidden::is($media)); @endphp
<div class="photo-cell {{ $hidden ? 'is-hidden' : '' }} {{ ! $readonly || $hide ? 'photo-cell--acts' : '' }}" data-id="{{ $media->id }}" data-hidden="{{ $hidden ? 1 : 0 }}">
<img src="{{ \App\Media\MediaUrl::for($media, 'w320') }}" data-full="{{ \App\Media\MediaUrl::for($media) }}" data-mid="{{ \App\Media\MediaUrl::for($media, 'w960') }}" alt="" loading="lazy" data-action="click->photos#open">
{{-- Главное — лаймовая звезда в углу, только метка (23.11.2026, владелец выбрал из макетов): сменить — «Главное» в просмотре кадра
     или долгим нажатием (правой кнопкой) по кадру (photos_controller#makeMain). --}}
@if ($main && !$hidden)
@php $isMain = $mainId !== null ? $media->id === $mainId : ! $mainShown; $mainShown = true; @endphp
@if ($isMain)<span class="photo-star photo-star--main" aria-label="Главное">@include('components.ui.star')</span>@endif
@endif
@if (! $readonly || $hide)
<div class="photo-actions">
{{-- Глаз переключается классом плитки (`is-hidden` меняет маску), не дожидаясь ответа сервера. --}}
@if ($hide)<button type="button" data-action="photos#act" data-act="hide" aria-label="{{ $hidden ? 'Показать' : 'Скрыть' }}" @if ($eyeTitle) title="{{ $eyeTitle }}" @endif><span class="pa-icon"></span></button>@endif
@if (! $readonly && $rotatable)<button type="button" data-action="photos#act" data-act="rotate" aria-label="Повернуть"><span class="pa-icon"></span></button>@endif
@if (! $readonly && ($deletable instanceof \Closure ? $deletable($media) : $deletable))<button type="button" data-action="photos#act" data-act="delete" data-confirm="Удалить фото?" aria-label="Удалить"><span class="pa-icon"></span></button>@endif
</div>
@endif
</div>
@endforeach
    {{-- Кадры, которые ещё качаются (лот Мигторга): заглушки с той же полосой скана, что у загрузки. --}}
    @for ($i = 0; $i < $pending; $i++)<div class="photo-cell photo-pending is-uploading is-processing" aria-hidden="true"></div>@endfor
    </div>
</div>
