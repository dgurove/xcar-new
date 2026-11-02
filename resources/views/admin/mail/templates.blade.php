<x-ui.cabinet title="Шаблоны">
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
