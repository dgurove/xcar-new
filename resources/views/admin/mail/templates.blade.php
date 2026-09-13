<x-ui.shell title="Шаблоны писем" narrow>
    <div class="flex flex-col gap-2">
        @foreach ($templates as $template)
            <a href="/nastroyki/shablony/{{ $template->id }}" class="row">
                <div class="min-w-0 flex-1">
                    <div class="font-medium">{{ $template->name }}</div>
                    <div class="mt-1 flex min-w-0 items-center gap-2"><span class="tag shrink-0">{{ $template->scope->label() }}</span><span class="truncate text-sm text-ink-muted">{{ $template->subject }}</span></div>
                </div>
                <x-ui.icon name="chevron-right" class="size-5 text-ink-dim"/>
            </a>
        @endforeach
        <a href="/nastroyki/shablony/novyy" class="btn btn-quiet self-start"><x-ui.icon name="plus" class="size-5"/> Шаблон</a>
    </div>
</x-ui.shell>
