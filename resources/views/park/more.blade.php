<x-ui.shell title="Ещё">
    <div class="flex flex-col gap-2">
        @foreach ([
            ['Из писем', 'mail', '/kandidaty'],
            ['Клиенты', 'user', '/klienty'],
            ['Ящики', 'mail', config('app.url').'/admin/yashchiki'],
            ['Шаблоны писем', 'file', config('app.url').'/admin/shablony'],
            ['Кабинет', 'user', config('app.url').'/lk'],
            ['Офферы', 'car', config('app.url').'/admin/offers'],
        ] as [$label, $icon, $href])
            <a href="{{ $href }}" class="row" @if (str_starts_with($href, 'http')) data-turbo="false" @endif>
                <x-ui.icon :name="$icon" class="size-5 text-ink-muted"/>
                <span class="flex-1">{{ $label }}</span>
                <x-ui.icon name="chevron-right" class="size-5 text-ink-dim"/>
            </a>
        @endforeach
        <form method="post" action="/vyhod" class="mt-2">@csrf<x-ui.button variant="ghost" block><x-ui.icon name="logout" class="size-5"/> Выйти</x-ui.button></form>
    </div>
</x-ui.shell>
