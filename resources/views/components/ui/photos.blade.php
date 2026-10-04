{{-- Ряд фотографий: плитка «добавить» стоит вне прокрутки, справа лента
     кадров со snap; на кадре действия глаз, поворот, корзина, скрытый —
     серый, главное — первый видимый (лаймовая звезда; нажатие по ней — выбор другого контурными звёздами), порядок перетаскиванием, клик по
     кадру — просмотрщик, файлы можно бросить на карточку. Живёт внутри
     data-controller="photos"; turbo-stream подменяет ряд целиком.
     readonly — только смотреть (кадры из письма рядом с приёмом): без плитки «добавить» и без действий; с hide — остаётся глаз.
     grid — сетка квадратов вместо ленты (дело на стоянке: все кадры видны сразу и в узкой колонке).
     deletable=false — без корзины (удаляют только в полном редакторе); замыкание — решает по кадру.
     pending — сколько заглушек «качается» в конце ряда. add=false — без плитки «добавить» (карточка строки парковки).
     Кнопка «Показать все» / «Скрыть все» — отдельно, x-ui.photos-all, там, где ей место по вёрстке.
     mainId — главный кадр всей машины, когда кадры разложены блоками по стадиям (PhotoBlocks): звезда у него, в чужом
     блоке — только контурные «сделать главным». --}}
@props(['photos', 'hide' => true, 'main' => true, 'mainId' => null, 'readonly' => false, 'id' => 'gallery', 'grid' => false, 'deletable' => true, 'pending' => 0, 'add' => true])
<div id="{{ $id }}" class="{{ $grid ? 'photo-grid' : 'photo-row' }}">
    @if (! $readonly && $add)<button type="button" class="photo-add" data-action="photos#pick" aria-label="Добавить фото"><x-ui.icon name="camera" class="size-6"/></button>@endif
    <div class="{{ $grid ? 'contents' : 'photo-strip' }}" data-photos-target="grid">
    @php $mainShown = false; @endphp
    @foreach ($photos as $media)
        @php $hidden = $hide && \App\Media\Hidden::is($media); @endphp
        <div class="photo-cell {{ $hidden ? 'is-hidden' : '' }}" data-id="{{ $media->id }}" data-hidden="{{ $hidden ? 1 : 0 }}">
            <img src="{{ \App\Media\MediaUrl::for($media, 'w320') }}" data-full="{{ \App\Media\MediaUrl::for($media) }}" data-mid="{{ \App\Media\MediaUrl::for($media, 'w960') }}" alt="" loading="lazy" data-action="click->photos#open">
            {{-- Главное — лаймовая звезда в углу; нажали — у остальных кадров контурные звёзды, нажатая становится главной. --}}
            @if ($main && !$hidden)
                @php $isMain = $mainId !== null ? $media->id === $mainId : ! $mainShown; $mainShown = true; @endphp
                @if ($readonly)
                    @if ($isMain)<span class="photo-star photo-star--main" aria-label="Главное">@include('components.ui.star')</span>@endif
                @else
                    <button type="button" class="photo-star {{ $isMain ? 'photo-star--main' : 'photo-star--pick' }}" data-action="photos#star" aria-label="{{ $isMain ? 'Выбрать главное' : 'Сделать главным' }}">@include('components.ui.star')</button>
                @endif
            @endif
            @if (! $readonly || $hide)
            <div class="photo-actions">
                {{-- Обе иконки сразу: глаз переключается классом плитки, не дожидаясь ответа сервера. --}}
                @if ($hide)<button type="button" data-action="photos#act" data-act="hide" aria-label="{{ $hidden ? 'Показать' : 'Скрыть' }}"><x-ui.icon name="eye-off" class="photo-eye-off size-4"/><x-ui.icon name="eye" class="photo-eye-on size-4"/></button>@endif
                @unless ($readonly)<button type="button" data-action="photos#act" data-act="rotate" aria-label="Повернуть"><x-ui.icon name="rotate" class="size-4"/></button>@endunless
                @if (! $readonly && ($deletable instanceof \Closure ? $deletable($media) : $deletable))<button type="button" data-action="photos#act" data-act="delete" data-confirm="Удалить фото?" aria-label="Удалить"><x-ui.icon name="trash" class="size-4"/></button>@endif
            </div>
            @endif
        </div>
    @endforeach
    {{-- Кадры, которые ещё качаются (лот Мигторга): заглушки с той же полосой скана, что у загрузки. --}}
    @for ($i = 0; $i < $pending; $i++)<div class="photo-cell photo-pending is-uploading is-processing" aria-hidden="true"></div>@endfor
    </div>
</div>
