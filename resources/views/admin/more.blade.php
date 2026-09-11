<x-ui.shell title="Ещё">
    <div class="flex flex-col gap-2">
        @foreach ([
            ['Кандидаты из писем', 'mail', '/admin/kandidaty'],
            ['Страховые и маршруты', 'shield', '/admin/strahovye'],
            ['Ящики', 'mail', '/admin/yashchiki'],
            ['Шаблоны писем', 'file', '/admin/shablony'],
            ['Кабинет', 'user', '/lk'],
            ['На сайт', 'car', '/'],
        ] as [$label, $icon, $href])
            <a href="{{ $href }}" class="row">
                <x-ui.icon :name="$icon" class="size-5 text-ink-muted"/>
                <span class="flex-1">{{ $label }}</span>
                <x-ui.icon name="chevron-right" class="size-5 text-ink-dim"/>
            </a>
        @endforeach
        <form method="post" action="/vyhod" class="mt-2">@csrf<x-ui.button variant="ghost" block><x-ui.icon name="logout" class="size-5"/> Выйти</x-ui.button></form>
    </div>
</x-ui.shell>
