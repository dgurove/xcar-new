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
        {{-- Покупатель: его менеджер строкой-контактом; с телефоном вся строка звонит. --}}
        <section class="mt-8">
            <h2 class="text-xl">Менеджер</h2>
            @php $tag = $manager->phone ? 'a' : 'div'; @endphp
            <{{ $tag }} @if ($manager->phone) href="tel:+{{ $manager->phone }}" @endif class="row mt-4 transition-colors hover:bg-hover">
                <x-ui.avatar :user="$manager" :size="48" class="text-lg"/>
                <span class="min-w-0 flex-1">
                    <span class="block truncate font-medium">{{ $manager->name }}</span>
                    @if ($manager->phone)<span class="row-sub"><span class="nums">{{ $manager->phoneFormatted() }}</span></span>@endif
                </span>
                @if ($manager->phone)<span class="btn btn-s btn-quiet btn-round"><x-ui.icon name="phone" class="size-5"/></span>@endif
            </{{ $tag }}>
        </section>
    @endif
    <div class="mt-8 flex flex-wrap gap-2">
        <a href="{{ $button[0] }}" class="btn btn-accent">{{ $button[1] }}</a>
        <button type="button" class="btn btn-quiet" hidden data-pwa-target="install" data-action="pwa#install">Установить приложение</button>
        @if (\App\Support\Surface::current() === \App\Support\Surface::Park)
            <form method="post" action="/vyhod" class="contents">@csrf<x-ui.button variant="secondary"><x-ui.icon name="exit" class="size-5"/> Выйти</x-ui.button></form>
        @endif
    </div>
</x-ui.cabinet>
