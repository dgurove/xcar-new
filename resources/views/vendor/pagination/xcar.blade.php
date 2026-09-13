{{-- Постраничная навигация: «Назад · n / m · Вперёд», без ряда номеров. На телефоне
     лента дотягивается сама (app.js, data-pages): следующая страница подшивается
     к списку, когда эта полоса подходит к экрану; сама полоса там спрятана. --}}
@if ($paginator->hasPages())
    <nav role="navigation" aria-label="Постраничная навигация" class="flex items-center justify-between gap-3" data-pages>
        @if ($paginator->onFirstPage())
            <span class="btn btn-s btn-quiet pointer-events-none opacity-40" aria-disabled="true"><x-ui.icon name="chevron-left" class="size-[18px]"/> Назад</span>
        @else
            <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="btn btn-s btn-quiet"><x-ui.icon name="chevron-left" class="size-[18px]"/> Назад</a>
        @endif
        <span class="nums text-sm font-normal text-ink-muted">{{ $paginator->currentPage() }} / {{ $paginator->lastPage() }}</span>
        @if ($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="btn btn-s btn-quiet">Вперёд <x-ui.icon name="chevron-right" class="size-[18px]"/></a>
        @else
            <span class="btn btn-s btn-quiet pointer-events-none opacity-40" aria-disabled="true">Вперёд <x-ui.icon name="chevron-right" class="size-[18px]"/></span>
        @endif
    </nav>
@endif
