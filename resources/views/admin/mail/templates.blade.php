<x-ui.shell title="Шаблоны писем" :trail="[['Главная', '/'], ['Настройки', '/nastroyki'], ['Шаблоны']]" narrow>
    <div class="flex flex-col gap-2">
        @foreach ($templates as $template)
            <a href="/nastroyki/shablony/{{ $template->id }}" class="row">
                <div class="min-w-0 flex-1">
                    <div class="font-medium">{{ $template->name }}</div>
                    <div class="truncate text-sm text-ink-muted">{{ $template->scope->label() }} · {{ $template->subject }}</div>
                </div>
                <x-ui.icon name="chevron-right" class="size-5 text-ink-dim"/>
            </a>
        @endforeach
        <a href="/nastroyki/shablony/novyy" class="btn btn-quiet self-start"><x-ui.icon name="plus" class="size-5"/> Шаблон</a>
    </div>
</x-ui.shell>
