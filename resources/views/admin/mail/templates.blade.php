<x-ui.shell title="Шаблоны писем" back="/admin/eshchyo">
    <div class="flex flex-col gap-2">
        @foreach ($templates as $template)
            <a href="/admin/shablony/{{ $template->id }}" class="row">
                <div class="min-w-0 flex-1">
                    <div class="font-medium">{{ $template->name }}</div>
                    <div class="truncate text-sm text-ink-muted">{{ $template->scope->label() }} · {{ $template->subject }}</div>
                </div>
                <x-ui.icon name="chevron-right" class="size-5 text-ink-dim"/>
            </a>
        @endforeach
        <a href="/admin/shablony/novyy" class="btn btn-secondary self-start"><x-ui.icon name="plus" class="size-5"/> Шаблон</a>
    </div>
</x-ui.shell>
