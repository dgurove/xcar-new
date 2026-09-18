{{-- Постраничная навигация: «‹ Назад», номера (первые, вокруг текущей, последние, между ними «…»),
     «Вперёд ›»; справа — «По 24 / 48 / 96» (sizes из x-ui.pager), когда записей больше первого
     размера. На телефоне то же, «Назад» и «Вперёд» — одними шевронами. Страница — заменой
     записи истории: «‹ Назад» в шапке ведёт в раздел, а не на прошлую страницу. --}}
@php
    $sizes ??= [];
    $choice = $sizes && $paginator->total() > min($sizes);
    $query = array_diff_key(request()->query(), ['page' => 1, \App\Support\ListView::PER => 1]);
@endphp
@if ($paginator->hasPages() || $choice)
    <nav role="navigation" aria-label="Постраничная навигация" class="flex flex-wrap items-center gap-3" data-pages>
        @if ($paginator->onFirstPage())
            <span class="btn btn-s btn-quiet pager-step pointer-events-none opacity-40" aria-disabled="true"><x-ui.icon name="chevron-left" class="size-[18px]"/><span class="hidden sm:inline">Назад</span></span>
        @else
            <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="btn btn-s btn-quiet pager-step" data-turbo-action="replace" aria-label="Назад"><x-ui.icon name="chevron-left" class="size-[18px]"/><span class="hidden sm:inline">Назад</span></a>
        @endif
        <div class="nums flex flex-wrap items-center gap-1">
            @foreach ($elements as $element)
                @if (is_string($element))
                    <span class="w-6 text-center text-ink-dim">…</span>
                @else
                    @foreach ($element as $page => $url)
                        @if ($page == $paginator->currentPage())
                            <span class="btn btn-s btn-accent btn-round pointer-events-none" aria-current="page">{{ $page }}</span>
                        @else
                            <a href="{{ $url }}" class="btn btn-s btn-quiet btn-round" data-turbo-action="replace">{{ $page }}</a>
                        @endif
                    @endforeach
                @endif
            @endforeach
        </div>
        @if ($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="btn btn-s btn-quiet pager-step" data-turbo-action="replace" aria-label="Вперёд"><span class="hidden sm:inline">Вперёд</span><x-ui.icon name="chevron-right" class="size-[18px]"/></a>
        @else
            <span class="btn btn-s btn-quiet pager-step pointer-events-none opacity-40" aria-disabled="true"><span class="hidden sm:inline">Вперёд</span><x-ui.icon name="chevron-right" class="size-[18px]"/></span>
        @endif
        @if ($choice)
            <form method="get" action="{{ $paginator->path() }}" class="ml-auto flex items-center" data-controller="autosubmit" data-turbo-action="replace">
                @foreach ($query as $k => $v)@if (!is_array($v))<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endif @endforeach
                <select name="{{ \App\Support\ListView::PER }}" class="field-input field-s !w-auto !bg-surface !pr-9" data-action="autosubmit#submit" aria-label="Сколько на странице">
                    @foreach ($sizes as $size)
                        <option value="{{ $size }}" @selected($size === $paginator->perPage())>По {{ $size }}</option>
                    @endforeach
                </select>
            </form>
        @endif
    </nav>
@endif
