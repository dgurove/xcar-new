<x-ui.cabinet title="Кабинет" :trail="[['Главная', '/'], ['Кабинет']]">
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        @foreach ($tiles as [$value, $forms, $href])
            <a href="{{ $href }}" class="box transition-colors hover:bg-accent-soft">
                <div class="nums text-[40px] leading-none">{{ $value }}</div>
                <div class="mt-3 text-sm text-ink-muted">{{ \App\Support\Plural::of($value, $forms) }}</div>
            </a>
        @endforeach
    </div>
    @if ($manager ?? null)
        {{-- Покупатель: кто его менеджер и как позвонить. --}}
        <div class="box mt-4 flex flex-wrap items-center gap-4">
            <x-ui.avatar :user="$manager" :size="48" class="text-lg"/>
            <div class="min-w-0 flex-1">
                <div class="font-medium">{{ $manager->name }}</div>
                <div class="text-sm text-ink-muted">ваш менеджер</div>
            </div>
            @if ($manager->phone)<a href="tel:+{{ $manager->phone }}" class="btn btn-s btn-quiet rounded-full">{{ $manager->phoneFormatted() }}</a>@endif
        </div>
    @endif
    <div class="mt-8 flex flex-wrap gap-2">
        <a href="{{ $button[0] }}" class="btn btn-accent">{{ $button[1] }}</a>
        <button type="button" class="btn btn-quiet" hidden data-pwa-target="install" data-action="pwa#install">Установить приложение</button>
        @if (\App\Support\Surface::current() === \App\Support\Surface::Park)
            <form method="post" action="/vyhod" class="contents">@csrf<x-ui.button variant="secondary"><x-ui.icon name="exit" class="size-5"/> Выйти</x-ui.button></form>
        @endif
    </div>
</x-ui.cabinet>
