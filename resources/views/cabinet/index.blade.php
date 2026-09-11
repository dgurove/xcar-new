<x-ui.cabinet title="Кабинет" :trail="[['Главная', '/'], ['Кабинет']]">
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        @foreach ($tiles as [$value, $forms, $href])
            <a href="{{ $href }}" class="box transition-colors hover:bg-accent-soft">
                <div class="nums text-[40px] leading-none">{{ $value }}</div>
                <div class="mt-3 text-sm text-ink-muted">{{ \App\Support\Plural::of($value, $forms) }}</div>
            </a>
        @endforeach
    </div>
    <a href="{{ $user->isStaff() ? '/admin/offers' : '/' }}" class="btn btn-accent mt-8">{{ $user->isStaff() ? 'К офферам' : 'В каталог' }}</a>
</x-ui.cabinet>
