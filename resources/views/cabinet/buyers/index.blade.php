{{-- Покупатели менеджера — один вход для всего, что про людей: сверху «Интерес» и «Пригласить»
     (свои экраны вложены сюда), затем группы — свои строки, последняя «Новая группа»; ниже люди.
     Поле поиска над всем (при поиске верхних блоков нет: ищут людей). --}}
<x-ui.cabinet title="Покупатели">
    {{-- Поиск — поле прямо на экране, без тулбара с одной кнопкой фильтра. --}}
    <form method="get" action="/account/buyers" data-turbo-action="replace" class="header-btn header-search relative h-11 justify-start rounded-full px-4" role="search">
        <x-ui.icon name="search" class="size-[18px] shrink-0 text-ink-muted"/>
        <input type="search" name="q" value="{{ $term }}" placeholder="Имя, логин, телефон" enterkeyhint="search" autocomplete="off" aria-label="Поиск">
    </form>

    @unless ($term)
        <div class="flex flex-col gap-2">
            <a href="/account/interest" class="row">
                <span class="flex size-11 shrink-0 items-center justify-center rounded-full bg-surface-3 text-ink"><x-ui.icon name="flag" class="size-5"/></span>
                <span class="min-w-0 flex-1 font-medium">Интерес</span>
                @if ($interestNew)
                    <x-ui.pill tone="accent" class="!min-h-0 !py-1 text-xs">{{ $interestNew }}</x-ui.pill>
                @elseif ($interestAll)
                    <span class="nums text-sm text-ink-muted">{{ $interestAll }}</span>
                @endif
                <x-ui.icon name="chevron-right" class="size-5 shrink-0 text-ink-dim"/>
            </a>
            <a href="/account/invites" class="row">
                <span class="flex size-11 shrink-0 items-center justify-center rounded-full bg-accent text-white"><x-ui.icon name="link" class="size-5"/></span>
                <span class="min-w-0 flex-1 font-medium">Пригласить</span>
                <x-ui.icon name="chevron-right" class="size-5 shrink-0 text-ink-dim"/>
            </a>
        </div>

        <section>
            @if ($groups->isNotEmpty())<h2 class="text-xl">Группы</h2>@endif
            <div class="{{ $groups->isNotEmpty() ? 'mt-4 ' : '' }}flex flex-col gap-2">
                @foreach ($groups as $g)
                    <a href="/account/buyers/groups/{{ $g->id }}" class="row">
                        <span class="flex size-11 shrink-0 items-center justify-center rounded-full bg-surface-3 text-ink"><x-ui.icon name="users" class="size-5"/></span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate font-medium">{{ $g->name }}</span>
                            <span class="row-sub">
                                <span class="tag nums">{{ $g->members_count }} {{ \App\Support\Plural::of($g->members_count, ['человек', 'человека', 'человек']) }}</span>
                                @if ($groupSeen[$g->id] ?? 0)<span class="tag nums">видит {{ $groupSeen[$g->id] }}</span>@endif
                            </span>
                        </span>
                        <x-ui.icon name="chevron-right" class="size-5 shrink-0 text-ink-dim"/>
                    </a>
                @endforeach
                <div data-controller="sheet" class="contents">
                    <button type="button" class="row w-full text-left" data-action="sheet#open">
                        <span class="flex size-11 shrink-0 items-center justify-center rounded-full bg-accent text-white"><x-ui.icon name="plus" class="size-5"/></span>
                        <span class="min-w-0 flex-1 font-medium">Новая группа</span>
                    </button>
                    <x-ui.sheet id="group-new" title="Новая группа" :open="$errors->has('name')">
                        <form method="post" action="/account/buyers/groups" class="flex flex-col gap-4">
                            @csrf
                            <x-ui.field name="name" label="Название" required maxlength="60" placeholder="Дилеры, Казань, VIP…"/>
                            <x-ui.button block>Создать</x-ui.button>
                        </form>
                    </x-ui.sheet>
                </div>
            </div>
        </section>
    @endunless

    <section>
        @if (!$term || $buyers->isNotEmpty())<h2 class="text-xl">Покупатели @if ($buyers->total())<span class="nums text-ink-dim">{{ $buyers->total() }}</span>@endif</h2>@endif
        @if ($buyers->isEmpty())
            @if ($term)
                <x-ui.empty href="/account/buyers" link="Все покупатели">Никого не нашлось</x-ui.empty>
            @else
                <x-ui.empty class="mt-4" href="/account/invites" link="Сделать ссылку">Покупатели приходят по вашей ссылке</x-ui.empty>
            @endif
        @else
            <div class="mt-4 flex flex-col gap-2">
                @foreach ($buyers as $buyer)
                    <div class="row relative">
                        <a href="/account/buyers/{{ $buyer->id }}" class="absolute inset-0 rounded-(--radius-l)" aria-label="{{ $buyer->name }}"></a>
                        <x-ui.avatar :user="$buyer" :size="44"/>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate font-medium">{{ $buyer->name }}</span>
                            <span class="row-sub">
                                @if ($buyer->login)<span class="tag nums">{{ $buyer->login }}</span>@endif
                                @if ($buyer->phone)<span class="tag nums">{{ $buyer->phoneFormatted() }}</span>@endif
                                @foreach ($buyer->groups as $g)<a href="/account/buyers/groups/{{ $g->id }}" class="tag relative z-10">{{ $g->name }}</a>@endforeach
                            </span>
                        </span>
                        {{-- Числа — столбиком, как цена и состояние в сделках. --}}
                        <span class="flex shrink-0 flex-col items-end gap-1.5">
                            @if ($interests[$buyer->id] ?? 0)<x-ui.pill tone="accent" class="!min-h-0 !py-1 text-xs">интерес {{ $interests[$buyer->id] }}</x-ui.pill>@endif
                            @if ($seen[$buyer->id] ?? 0)<span class="nums text-sm text-ink-muted">видит {{ $seen[$buyer->id] }}</span>@endif
                        </span>
                        <x-ui.icon name="chevron-right" class="size-5 shrink-0 text-ink-dim"/>
                    </div>
                @endforeach
            </div>
            <x-ui.pager :of="$buyers" :sizes="[]"/>
        @endif
    </section>

    {{-- На телефоне — ещё и плашка внизу; на компьютере хватает строки в списке. --}}
    <div class="contents md:hidden">
        <x-ui.action-bar>
            <a href="/account/invites" class="btn btn-accent min-w-0 flex-1"><x-ui.icon name="link" class="size-5"/> Пригласить</a>
        </x-ui.action-bar>
    </div>
</x-ui.cabinet>
