{{-- Содержимое окошка строки таблицы (фрейм peek), сверху вниз: лента фото (тап —
     во весь экран), название с метками и ценой справа (aside), факты чипами, ряд
     действий (actions: главное лаймовое + чипы; без него — «Открыть»), дальше тело —
     формы, характеристики, описание. row — свежая строка таблицы <tr>, ею
     peek_controller заменяет выделенную после действия; flash-сообщение и просьба
     перейти к следующей (session peek-advance) едут шаблонами — их читает он же.
     photo — одно фото вместо ленты (старые окошки); :photo="false" — без кадра вовсе (ветка почты). --}}
@props(['href', 'title', 'photos' => null, 'photo' => null, 'marks' => null, 'facts' => [], 'aside' => null, 'actions' => null, 'action' => 'Открыть', 'row' => null])
@if ($photos !== null)
    <x-offer.gallery :photos="$photos" :alt="$title" strip/>
@endif
<div class="flex items-start gap-3 {{ $photos !== null ? 'mt-3' : '' }}">
    @if ($photos === null && $photo !== false)<a href="{{ $href }}" class="peek-photo">@if ($photo)<x-offer.photo :media="$photo" sizes="96px" eager/>@else<x-ui.car-blank/>@endif</a>@endif
    <div class="min-w-0 flex-1">
        <a href="{{ $href }}" class="block text-[17px] leading-snug hover:text-accent-text"><span class="line-clamp-2">{{ $title }}</span></a>
        @if ($marks)<div class="mt-1.5 flex flex-wrap items-center gap-1.5">{{ $marks }}</div>@endif
    </div>
    @if ($aside && $photos !== null)<div class="shrink-0 text-right">{{ $aside }}</div>@endif
</div>
@if ($facts = array_filter($facts))<div class="mt-3 flex flex-wrap gap-1.5">@foreach ($facts as $fact)<span class="tag nums">{{ $fact }}</span>@endforeach</div>@endif
@if ($actions)
    <div class="mt-3 flex flex-wrap items-center gap-2">{{ $actions }}</div>
@elseif ($action)
    <div class="mt-3 flex items-center gap-3">
        <a href="{{ $href }}" class="btn btn-s btn-accent flex-1 whitespace-nowrap sm:flex-none">{{ $action }}</a>
        @if ($aside && $photos === null)<div class="ml-auto min-w-0 text-right">{{ $aside }}</div>@endif
    </div>
@endif
{{ $slot }}
@if ($row)<template data-peek-row>{{ $row }}</template>@endif
@if ($errors->any())<p class="field-error mt-3">{{ $errors->first() }}</p>@endif
@if (session('toast') || session('toast-danger'))<template data-peek-toast data-message="{{ session('toast-danger') ?? session('toast') }}" data-kind="{{ session('toast-danger') ? 'danger' : '' }}"></template>@endif
@if (session('peek-advance'))<template data-peek-advance></template>@endif
