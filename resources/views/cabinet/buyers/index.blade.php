{{-- Покупатели менеджера: пилюли — группы, строка — человек-контакт с тем, что о нём важно.
     Главное действие — «Пригласить» — в полосе внизу. --}}
<x-ui.cabinet title="Покупатели" :trail="[['Главная', '/'], ['Кабинет', '/lk'], ['Покупатели']]">
    <x-ui.toolbar :pills="$pills" :pill="(string) ($group ?? '')" pill-param="group" :counts="$counts" name="buyers" action="/lk/pokupateli">
        <x-slot:pillsExtra>
            <div data-controller="sheet" class="contents">
                <button type="button" class="pill shrink-0" data-action="sheet#open"><x-ui.icon name="plus" class="size-4"/> Группа</button>
                <x-ui.sheet id="group-new" title="Новая группа">
                    <form method="post" action="/lk/pokupateli/gruppy" class="flex flex-col gap-4">
                        @csrf
                        <x-ui.field name="name" label="Название" required maxlength="60" placeholder="Дилеры, Казань, VIP…" autofocus/>
                        <x-ui.button block>Создать</x-ui.button>
                    </form>
                </x-ui.sheet>
            </div>
            <a href="/lk/priglasheniya" class="pill shrink-0"><x-ui.icon name="link" class="size-4"/> Ссылки@if ($invites) <span class="nums opacity-70">{{ $invites }}</span>@endif</a>
        </x-slot:pillsExtra>
        <x-slot:filters>
            <input name="q" value="{{ request('q') }}" placeholder="Имя, логин, телефон" class="field-input field-s">
        </x-slot:filters>
    </x-ui.toolbar>

    @if ($group)
        @php($current = $groups->firstWhere('id', $group))
        <a href="/lk/pokupateli/gruppy/{{ $group }}" class="mt-6 flex items-center gap-2 text-xl transition-colors hover:text-accent-text">
            <span class="flex size-9 items-center justify-center rounded-full bg-surface-3 text-ink"><x-ui.icon name="users" class="size-5"/></span>
            {{ $current?->name }}
            <x-ui.icon name="chevron-right" class="size-5 text-ink-dim"/>
        </a>
    @endif

    @if ($buyers->isEmpty())
        @if ($group || request('q'))
            <x-ui.empty class="mt-6" href="/lk/pokupateli" link="Все покупатели">Здесь пока никого.</x-ui.empty>
        @else
            <x-ui.empty class="mt-6">Покупателей пока нет — отправьте им ссылку.</x-ui.empty>
        @endif
    @else
        <div class="mt-6 grid gap-2 xl:grid-cols-2">
            @foreach ($buyers as $buyer)
                <a href="/lk/pokupateli/{{ $buyer->id }}" class="row transition-colors hover:bg-hover">
                    <x-ui.avatar :user="$buyer" :size="44"/>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate font-medium">{{ $buyer->name }}</span>
                        <span class="row-sub">
                            @if ($buyer->login)<span class="tag nums">{{ $buyer->login }}</span>@endif
                            @if ($buyer->phone)<span class="tag nums">{{ $buyer->phoneFormatted() }}</span>@endif
                            @foreach ($buyer->groups as $g)<span class="tag">{{ $g->name }}</span>@endforeach
                        </span>
                    </span>
                    <span class="flex shrink-0 items-center gap-2">
                        @if ($interests[$buyer->id] ?? 0)<x-ui.pill tone="accent" class="!min-h-0 !py-1 text-xs">интерес {{ $interests[$buyer->id] }}</x-ui.pill>@endif
                        @if ($seen[$buyer->id] ?? 0)<span class="nums text-sm text-ink-muted">видит {{ $seen[$buyer->id] }}</span>@endif
                        <x-ui.icon name="chevron-right" class="size-5 text-ink-dim"/>
                    </span>
                </a>
            @endforeach
        </div>
        <div class="mt-8">{{ $buyers->links() }}</div>
    @endif

    <x-ui.action-bar>
        <a href="/lk/priglasheniya" class="btn btn-accent min-w-0 flex-1 md:flex-none"><x-ui.icon name="link" class="size-5"/> Пригласить</a>
    </x-ui.action-bar>
</x-ui.cabinet>
