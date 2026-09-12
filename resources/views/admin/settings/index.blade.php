<x-ui.shell title="Настройки" :trail="[['Главная', '/'], ['Настройки']]">
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ($tiles as [$title, $value, $forms, $href])
            <a href="{{ $href }}" class="box transition-colors hover:bg-accent-soft">
                <div class="text-lg">{{ $title }}</div>
                <div class="nums mt-4 text-[40px] leading-none">{{ $value }}</div>
                <div class="mt-3 text-sm text-ink-muted">{{ \App\Support\Plural::of($value, $forms) }}</div>
            </a>
        @endforeach
    </div>
</x-ui.shell>
