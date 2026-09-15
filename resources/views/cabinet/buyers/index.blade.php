{{-- Покупатели менеджера: пилюли — группы, строка — человек с тем, что о нём важно: сколько видит и есть ли интерес. --}}
<x-ui.cabinet title="Покупатели" :trail="[['Главная', '/'], ['Кабинет', '/lk'], ['Покупатели']]">
    <x-ui.toolbar :pills="$pills" :pill="(string) ($group ?? '')" pill-param="group" :counts="$counts" name="buyers" action="/lk/pokupateli">
        <x-slot:extra>
            <div data-controller="sheet" class="contents">
                <button type="button" class="btn btn-s btn-quiet shrink-0 rounded-full" data-action="sheet#open"><x-ui.icon name="plus" class="size-4"/><span class="hidden sm:inline">Группа</span></button>
                <x-ui.sheet id="group-new" title="Новая группа">
                    <form method="post" action="/lk/pokupateli/gruppy" class="flex flex-col gap-4">
                        @csrf
                        <x-ui.field name="name" label="Название" required maxlength="60" placeholder="Дилеры, Казань, VIP…" autofocus/>
                        <x-ui.button block>Создать</x-ui.button>
                    </form>
                </x-ui.sheet>
            </div>
            <a href="/lk/pokupateli/priglasheniya" class="btn btn-s btn-accent shrink-0 rounded-full"><x-ui.icon name="link" class="size-4"/><span class="hidden sm:inline">Пригласить</span></a>
        </x-slot:extra>
        <x-slot:filters>
            <input name="q" value="{{ request('q') }}" placeholder="Имя, логин, телефон" class="field-input field-s">
        </x-slot:filters>
    </x-ui.toolbar>

    @if ($group)
        @php($current = $groups->firstWhere('id', $group))
        <div class="mt-4 flex items-center gap-2">
            <a href="/lk/pokupateli/gruppy/{{ $group }}" class="btn btn-s btn-quiet rounded-full"><x-ui.icon name="users" class="size-4"/> {{ $current?->name }}: состав и что видит</a>
        </div>
    @endif

    @if ($buyers->isEmpty())
        @if ($group || request('q'))
            <x-ui.empty class="mt-6" href="/lk/pokupateli" link="Все покупатели">Здесь пока никого.</x-ui.empty>
        @else
            <x-ui.empty class="mt-6" href="/lk/pokupateli/priglasheniya" link="Создать пригласительную ссылку">Покупателей пока нет. Пришлите им ссылку — по ней они зарегистрируются и привяжутся к вам.</x-ui.empty>
        @endif
    @else
        <div class="mt-6 flex flex-col gap-2">
            @foreach ($buyers as $buyer)
                <a href="/lk/pokupateli/{{ $buyer->id }}" class="row">
                    <x-ui.avatar :user="$buyer" :size="40"/>
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-x-2 gap-y-0.5">
                            <span class="truncate font-medium">{{ $buyer->name }}</span>
                            @if ($interests[$buyer->id] ?? 0)<x-ui.pill tone="accent" class="!min-h-0 !py-0.5 text-xs">интерес {{ $interests[$buyer->id] }}</x-ui.pill>@endif
                        </div>
                        <div class="mt-1 flex flex-wrap items-center gap-1.5">
                            @if ($buyer->login)<span class="tag nums">{{ $buyer->login }}</span>@endif
                            @if ($buyer->phone)<span class="tag nums">{{ $buyer->phoneFormatted() }}</span>@endif
                            @foreach ($buyer->groups as $g)<span class="tag">{{ $g->name }}</span>@endforeach
                            <span class="tag {{ ($seen[$buyer->id] ?? 0) ? '' : 'opacity-60' }}">{{ ($seen[$buyer->id] ?? 0) ? 'видит '.$seen[$buyer->id] : 'ничего не открыто' }}</span>
                        </div>
                    </div>
                    <x-ui.icon name="chevron-right" class="size-5 shrink-0 text-ink-dim"/>
                </a>
            @endforeach
        </div>
        <div class="mt-8">{{ $buyers->links() }}</div>
    @endif
</x-ui.cabinet>
