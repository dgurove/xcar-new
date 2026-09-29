{{-- Ряд фотографий: плитка «добавить» стоит вне прокрутки, справа лента
     кадров со snap; на кадре действия глаз, поворот, корзина, скрытый —
     серый, главное — первый видимый, порядок перетаскиванием, клик по
     кадру — просмотрщик, файлы можно бросить на карточку. Живёт внутри
     data-controller="photos"; turbo-stream подменяет ряд целиком.
     readonly — только смотреть (кадры из письма рядом с приёмом): без плитки «добавить» и без действий.
     grid — сетка квадратов вместо ленты (дело на стоянке: все кадры видны сразу и в узкой колонке).
     tapHides — нажатие по кадру прячет его или возвращает (окошко предложения перед публикацией), во весь экран — значком;
     deletable=false — без корзины (удаляют только в полном редакторе). --}}
@props(['photos', 'hide' => true, 'main' => true, 'readonly' => false, 'id' => 'gallery', 'grid' => false, 'tapHides' => false, 'deletable' => true])
<div id="{{ $id }}" class="{{ $grid ? 'photo-grid' : 'photo-row' }}{{ $tapHides ? ' photo-tap' : '' }}">
    @unless ($readonly)<button type="button" class="photo-add" data-action="photos#pick" aria-label="Добавить фото"><x-ui.icon name="camera" class="size-7"/></button>@endunless
    <div class="{{ $grid ? 'contents' : 'photo-strip' }}" data-photos-target="grid">
    @php $mainShown = false; @endphp
    @foreach ($photos as $media)
        @php $hidden = $hide && $media->getCustomProperty('hidden', false); @endphp
        <div class="photo-cell {{ $hidden ? 'is-hidden' : '' }}" data-id="{{ $media->id }}" data-hidden="{{ $hidden ? 1 : 0 }}">
            <img src="{{ \App\Media\MediaUrl::for($media, 'w320') }}" data-full="{{ \App\Media\MediaUrl::for($media) }}" data-mid="{{ \App\Media\MediaUrl::for($media, 'w960') }}" alt="" loading="lazy" @if ($tapHides && $hide && ! $readonly) data-action="click->photos#act" data-act="hide" @else data-action="click->photos#open" @endif>
            @if ($main && !$hidden && !$mainShown)<span class="mark mark-accent photo-main">Главное</span>@php $mainShown = true; @endphp@endif
            @if ($tapHides && $hidden)<span class="mark photo-main">Скрыто</span>@endif
            @unless ($readonly)
            <div class="photo-actions">
                @if ($tapHides)<button type="button" data-action="photos#open" aria-label="Во весь экран"><x-ui.icon name="expand" class="size-4"/></button>
                @elseif ($hide)<button type="button" data-action="photos#act" data-act="hide" aria-label="{{ $hidden ? 'Показать' : 'Скрыть' }}"><x-ui.icon name="{{ $hidden ? 'eye' : 'eye-off' }}" class="size-4"/></button>@endif
                <button type="button" data-action="photos#act" data-act="rotate" aria-label="Повернуть"><x-ui.icon name="rotate" class="size-4"/></button>
                @if ($deletable)<button type="button" data-action="photos#act" data-act="delete" data-confirm="Удалить фото?" aria-label="Удалить"><x-ui.icon name="trash" class="size-4"/></button>@endif
            </div>
            @endunless
        </div>
    @endforeach
    </div>
</div>
