<x-ui.cabinet title="Шаблоны">
    {{-- Доп. запросы поставщику (17.11.2026, `Offers\ExtraRequest`): галки окна «Сделать доп. запрос», порядок — перетаскиванием
         за ручку, он же порядок фраз письма; под названием — фраза после «Просьба …». --}}
    @if ($requests->isNotEmpty() || $base === '/settings/templates')
        <p class="list-head">Доп. запросы</p>
        <div class="list" data-controller="list-order" data-list-order-url-value="{{ $base }}/order">
            @foreach ($requests as $template)
                <div class="row" data-id="{{ $template->id }}">
                    <span class="-ml-1 cursor-grab text-ink-dim md:touch-none" data-handle aria-label="Перетащить"><x-ui.icon name="grip" class="size-5"/></span>
                    <a href="{{ $base }}/{{ $template->id }}" class="flex min-w-0 flex-1 items-center gap-3">
                        <div class="min-w-0 flex-1">
                            <div class="font-medium">{{ $template->name }}</div>
                            <div class="mt-1 truncate text-sm text-ink-muted">Просьба {{ \App\Offers\ExtraRequest::phrase(collect([$template]), $template->choices ? implode(' / ', $template->choices) : null) }}{{ $template->attach === \App\Mail\Template::PAY_PROOF ? ', платёжка во вложении' : '' }}</div>
                        </div>
                        <x-ui.icon name="chevron-right" class="size-5 text-ink-dim"/>
                    </a>
                </div>
            @endforeach
            <a href="{{ $base }}/new?kind=request" class="row">
                <x-ui.row-icon name="plus" tone="accent"/>
                <span class="min-w-0 flex-1 font-medium">Новый запрос</span>
            </a>
        </div>
        <p class="list-head mt-6">Письма</p>
    @endif
    <div class="list">
        <a href="{{ $base }}/new" class="row">
            <x-ui.row-icon name="plus" tone="accent"/>
            <span class="min-w-0 flex-1 font-medium">Новый шаблон</span>
        </a>
        @foreach ($templates as $template)
            <a href="{{ $base }}/{{ $template->id }}" class="row">
                <div class="min-w-0 flex-1">
                    <div class="font-medium">{{ $template->name }}</div>
                    <div class="mt-1 flex min-w-0 items-center gap-2">@if ($base === '/settings/templates')<span class="shrink-0 text-sm text-ink-muted">{{ $template->scope->label() }}</span>@endif<span class="truncate text-sm text-ink-muted">{{ $template->subject ?: \Illuminate\Support\Str::limit(strip_tags((string) $template->body), 90) }}</span></div>
                </div>
                <x-ui.icon name="chevron-right" class="size-5 text-ink-dim"/>
            </a>
        @endforeach
    </div>
</x-ui.cabinet>
