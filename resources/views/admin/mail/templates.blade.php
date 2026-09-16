<x-ui.cabinet title="Шаблоны">
    <div class="flex flex-col gap-2">
        <a href="/settings/templates/new" class="row">
            <span class="flex size-11 shrink-0 items-center justify-center rounded-full bg-accent text-white"><x-ui.icon name="plus" class="size-5"/></span>
            <span class="min-w-0 flex-1 font-medium">Новый шаблон</span>
        </a>
        @foreach ($templates as $template)
            <a href="/settings/templates/{{ $template->id }}" class="row">
                <div class="min-w-0 flex-1">
                    <div class="font-medium">{{ $template->name }}</div>
                    <div class="mt-1 flex min-w-0 items-center gap-2"><span class="tag shrink-0">{{ $template->scope->label() }}</span><span class="truncate text-sm text-ink-muted">{{ $template->subject }}</span></div>
                </div>
                <x-ui.icon name="chevron-right" class="size-5 text-ink-dim"/>
            </a>
        @endforeach
    </div>
</x-ui.cabinet>
