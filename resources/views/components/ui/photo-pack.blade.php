{{-- Пачка кадров обложкой (23.11.2026, владелец: ленты «неудобно и некрасиво, избавиться полностью»): подпись «Фото от страховой
     22.09», обложка 4:3, под ней «Открыто N из M». Нажатие — шторка с кадрами пачки плиткой, как вложения в iOS и мессенджерах:
     первая клетка «добавить», дальше квадраты во всю ширину шторки; открытый кадр отмечен белым кружком с глазом в углу,
     скрытый — пустым кольцом (владелец: «выделяются те, что выбраны»); поворот и корзина — в просмотре кадра во весь экран.
     Пачки стоят рядом сеткой `.photo-packs` (две в строку). Обёртка — сам photos_controller (значения — атрибутами) и
     sheet_controller; слот — тело шторки: поле выбора файлов и `x-ui.photos grid`. Глаз считается так же, как в ряду
     (`hideBy` или `Media\Hidden`); счёт и тусклую обложку после нажатий пересчитывает контроллер (`count`, `cover`).
     hide=false — глаза нет, под обложкой просто «N фото». --}}
@props(['id', 'photos', 'title' => null, 'when' => null, 'hide' => true, 'hideBy' => null, 'mainId' => null])
@php
    $hidden = fn ($m) => $hide && ($hideBy ? $hideBy($m) : \App\Media\Hidden::is($m));
    $total = $photos->count();
    $open = $photos->reject($hidden)->count();
    // Обложка — главный кадр машины, если он в пачке, иначе первый открытый, иначе первый.
    $cover = ($mainId ? $photos->firstWhere('id', $mainId) : null) ?? $photos->first(fn ($m) => ! $hidden($m)) ?? $photos->first();
    // Счёт: при глазе — значок открытого глаза и «14 из 28» (23.11.2026, владелец: «„Открыто“ заменим на иконку глаза»),
    // без глаза — «28 фото». Тот же вид пишет photos_controller#syncCount после нажатий.
    $eye = $hide && $total > 0;
    $words = $total === 0 ? 'Нет фото' : ($hide ? $open.' из '.$total : $total.' фото');
    $label = $title ?? 'Фото';
    // На обложке — без «Фото»: «От страховой 09.10», «При приёме 09.10» одной строкой, где бы пачка ни стояла (23.11.2026,
    // владелец: перенос даты на вторую строку «стрёмный»). В шапке шторки — полностью.
    $short = str_starts_with($label, 'Фото ') ? \Illuminate\Support\Str::ucfirst(mb_substr($label, 5)) : $label;
    $head = new \Illuminate\Support\HtmlString(e($label).($when ? ' <span class="nums text-ink-dim">'.e($when).'</span>' : ''));
@endphp
<div {{ $attributes->class('photo-pack') }} data-controller="photos sheet">
    <button type="button" class="photo-pack-cover" data-action="sheet#open">
        <span class="photo-pack-title"><span>{{ $short }}</span>@if ($when)<span class="nums text-ink-dim">{{ $when }}</span>@endif</span>
        <span @class(['photo-pack-img', 'is-hidden' => $hide && $total && ! $open]) data-photos-target="cover">
            @if ($cover)
                <img src="{{ \App\Media\MediaUrl::for($cover, 'w320') }}" alt="" loading="lazy">
            @else
                <x-ui.icon name="camera" class="size-6"/>
            @endif
        </span>
        <span class="photo-pack-sub photo-count" data-photos-target="count">@if ($eye)<x-ui.icon name="eye" class="size-3.5"/>@endif{{ $words }}</span>
    </button>
    <x-ui.sheet :id="$id.'-pack'" :title="$head" wide class="photo-pack-sheet">
        <div class="photo-pack-bar"><span class="photo-count" data-photos-target="count">@if ($eye)<x-ui.icon name="eye" class="size-3.5"/>@endif{{ $words }}</span>@if ($hide)<x-ui.photos-all/>@endif</div>
        {{ $slot }}
    </x-ui.sheet>
</div>
