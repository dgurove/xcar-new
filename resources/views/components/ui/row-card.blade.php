{{-- Карточка строки списка (во фрейме detail, x-ui.detail), сверху вниз: полоса (ручка листа на телефоне, кнопки
     слева стрелки по строкам и «3 из 20», справа кнопки строки tools («Поделиться»), «Открыть страницу», «Закрыть»), лента фото (тап — во весь экран), название с
     метками и ценой справа (aside), факты чипами, ряд действий (actions: главное лаймовое + чипы; без него —
     «Открыть»), дальше тело — формы, характеристики, описание. Ответ после формы несёт потоки Turbo: свежая строка
     таблицы (row — <tr> с тем же data-detail-key), тост из flash, «следующий без цены» (session detail-advance).
     photo — одно фото вместо ленты (старые карточки); :photo="false" — без кадра вовсе (ветка почты).
     media — свой ряд кадров вместо ленты (карточка предложения CRM: кадры правят прямо тут). --}}
@props(['href' => null, 'title', 'photos' => null, 'photo' => null, 'marks' => null, 'facts' => [], 'aside' => null, 'actions' => null, 'action' => 'Открыть', 'row' => null, 'tools' => null, 'media' => null])
<div class="detail-bar" data-action="pointerdown->detail#grab">
    <span class="detail-handle" aria-hidden="true"></span>
    <button type="button" class="bar-btn" data-action="detail#prev" aria-label="Предыдущая"><x-ui.icon name="chevron-down" class="size-[18px] rotate-180"/></button>
    <button type="button" class="bar-btn" data-action="detail#next" aria-label="Следующая"><x-ui.icon name="chevron-down" class="size-[18px]"/></button>
    <span class="detail-count nums mr-auto" data-detail-target="count"></span>
    <span id="detail-tools" class="contents">{{ $tools }}</span>
    @if ($href)<a class="bar-btn" href="{{ $href }}" data-turbo-frame="_top" aria-label="Открыть страницу"><x-ui.icon name="expand" class="size-[18px]"/></a>@endif
    <a class="bar-btn" href="{{ \App\Support\Detail::close() }}" data-turbo-frame="detail" data-turbo-action="replace" data-turbo-prefetch="false" data-detail-target="close" aria-label="Закрыть"><x-ui.icon name="x" class="size-[18px]"/></a>
</div>
<div class="detail-body">
@if ($photos !== null || $media)
    @if ($media){{ $media }}@else<x-offer.gallery :photos="$photos" :alt="$title" strip/>@endif
    {{-- Шапка с лентой: название, справа цена, под названием метки; на телефоне цена — своей строкой под названием,
         метки — во всю ширину рядом (узкая колонка рядом с ценой ставила их столбиком). --}}
    <div class="detail-head mt-3">
        <a @if ($href) href="{{ $href }}" @endif class="detail-title block text-lg leading-snug hover:text-accent-text"><span class="line-clamp-2">{{ $title }}{{ $badge ?? '' }}</span></a>
        @if ($aside && trim($aside) !== '')<div class="detail-aside">{{ $aside }}</div>@endif
        {{-- Метки и факты — одной строкой с одним шагом: два ряда тегов с разными отступами смотрелись вразнобой. --}}
        @if ($marks || array_filter($facts))<div class="detail-marks flex flex-wrap items-center gap-1.5">{{ $marks }}@foreach (array_filter($facts) as $fact)<span class="tag nums">{{ $fact }}</span>@endforeach</div>@endif
    </div>
@else
<div class="flex items-start gap-3">
    @if ($photo !== false)<a href="{{ $href }}" class="detail-photo">@if ($photo)<x-offer.photo :media="$photo" sizes="96px" eager/>@else<x-ui.car-blank/>@endif</a>@endif
    <div class="min-w-0 flex-1">
        <a @if ($href) href="{{ $href }}" @endif class="block text-lg leading-snug hover:text-accent-text"><span class="line-clamp-2">{{ $title }}{{ $badge ?? '' }}</span></a>
        @if ($marks)<div class="mt-1.5 flex flex-wrap items-center gap-1.5">{{ $marks }}</div>@endif
    </div>
</div>
@endif
@if ($photos === null && ($facts = array_filter($facts)))<div class="mt-3 flex flex-wrap gap-1.5">@foreach ($facts as $fact)<span class="tag nums">{{ $fact }}</span>@endforeach</div>@endif
{{-- Пустой слот (черновик без кнопок) не рисует ряд: иначе над «Ценами» лишний отступ. --}}
@if ($actions && (! $actions instanceof \Illuminate\View\ComponentSlot || $actions->hasActualContent()))
    <div class="mt-3 flex flex-wrap items-center gap-2">{{ $actions }}</div>
@elseif ($action && $href)
    <div class="mt-3 flex items-center gap-3">
        <a href="{{ $href }}" class="btn btn-s btn-accent flex-1 whitespace-nowrap sm:flex-none">{{ $action }}</a>
        @if ($aside && trim($aside) !== '' && $photos === null)<div class="ml-auto min-w-0 text-right">{{ $aside }}</div>@endif
    </div>
@endif
{{ $slot }}
@if (($errors ?? null)?->any())<p class="field-error mt-3">{{ $errors->first() }}</p>@endif
</div>
{{-- detail-gone — строка ушла из списка (заполнили закупочную или оценили — она во вкладке дальше; в архив, опубликовали): убрать
     строку таблицы или плитку, а не заменить;
     detail-counts — новые числа у пилюль (выросшее вспыхивает, StreamActions.count). --}}
@if (session('detail-gone'))<turbo-stream action="remove" targets="tr[data-detail-key=&quot;{{ \App\Support\Detail::key() }}&quot;], article[data-detail-key=&quot;{{ \App\Support\Detail::key() }}&quot;]"></turbo-stream>
@foreach ((array) session('detail-counts') as $pill => $n)<turbo-stream action="count" count="{{ (int) $n }}" targets="[data-pill-count=&quot;{{ $pill }}&quot;]"></turbo-stream>@endforeach
@elseif ($row)<turbo-stream action="replace" targets="tr[data-detail-key=&quot;{{ \App\Support\Detail::key() }}&quot;]"><template>{{ $row }}</template></turbo-stream>@endif
@if (session('toast') || session('toast-danger'))<turbo-stream action="toast" data-message="{{ session('toast-danger') ?? session('toast') }}" data-kind="{{ session('toast-danger') ? 'danger' : '' }}" @if (session('toast-undo')) data-undo @endif></turbo-stream>@endif
@if (session('detail-advance'))<turbo-stream action="advance"></turbo-stream>@endif
