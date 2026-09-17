{{-- Содержимое окошка строки таблицы: фото слева, название, метки, факты
     чипами, справа цена (aside), внизу действие «Открыть» на страницу. --}}
@props(['href', 'title', 'photo' => null, 'marks' => null, 'facts' => [], 'aside' => null, 'action' => 'Открыть'])
<div class="flex items-start gap-3 pr-8">
    <a href="{{ $href }}" class="peek-photo">@if ($photo)<x-offer.photo :media="$photo" sizes="96px" eager/>@else<x-ui.car-blank/>@endif</a>
    <div class="min-w-0 flex-1">
        <a href="{{ $href }}" class="block text-[17px] leading-snug hover:text-accent-text"><span class="line-clamp-2">{{ $title }}</span></a>
        @if ($marks)<div class="mt-1.5 flex flex-wrap items-center gap-1.5">{{ $marks }}</div>@endif
    </div>
</div>
@if ($facts = array_filter($facts))<div class="mt-3 flex flex-wrap gap-1.5">@foreach ($facts as $fact)<span class="tag nums">{{ $fact }}</span>@endforeach</div>@endif
{{ $slot }}
<div class="mt-3 flex items-center gap-3">
    <a href="{{ $href }}" class="btn btn-s btn-accent flex-1 whitespace-nowrap sm:flex-none">{{ $action }}</a>
    @if ($aside)<div class="ml-auto min-w-0 text-right">{{ $aside }}</div>@endif
</div>
