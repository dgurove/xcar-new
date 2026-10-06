{{-- Покупатели менеджера — вторая пилюля раздела «Сделки», один вход для всего, что про людей: сверху «Интерес» и
     «Пригласить» (свои экраны вложены сюда), затем группы — свои строки, последняя «Новая группа»; ниже люди.
     Лупа справа от пилюль (при поиске верхних блоков нет: ищут людей). --}}
<x-ui.shell title="Покупатели" :phone-heading="false" :desktop-heading="false">
<div class="flex max-w-[56rem] flex-col gap-4">
    {{-- Лупа — справа от пилюль раздела; в поиске поле встаёт на их место. --}}
    <div class="search-head">
        <div class="min-w-0 flex-1"><x-deal.tabs current="/buyers"/></div>
        <x-ui.toolbar :sort="$term ? null : $sort" search="Имя, логин, телефон" name="buyers"/>
    </div>

    <div id="list" class="contents">

    @unless ($term)
        <div class="list">
            <a href="/buyers/interest" class="row">
                <x-ui.row-icon name="flag"/>
                <span class="min-w-0 flex-1 font-medium">Интерес</span>
                @if ($interestNew)
                    <span class="badge">{{ $interestNew }}</span>
                @elseif ($interestAll)
                    <span class="nums text-sm text-ink-muted">{{ $interestAll }}</span>
                @endif
                <x-ui.chevron/>
            </a>
            {{-- На телефоне «Пригласить» — плашка внизу экрана; строкой — только на компьютере. --}}
            <a href="/account/invites" class="row max-md:!hidden">
                <x-ui.row-icon name="link" tone="accent"/>
                <span class="min-w-0 flex-1 font-medium">Пригласить</span>
                <x-ui.chevron/>
            </a>
        </div>

        <section>
            @if ($groups->isNotEmpty())<h2 class="list-head">Группы</h2>@endif
            <div class="list">
                @foreach ($groups as $g)
                    <a href="/buyers/groups/{{ $g->id }}" class="row">
                        <x-ui.row-icon name="users"/>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate font-medium">{{ $g->name }}</span>
                            <span class="row-sub">
                                <span class="tag nums">{{ $g->members_count }} {{ \App\Support\Plural::of($g->members_count, ['человек', 'человека', 'человек']) }}</span>
                                @if ($groupSeen[$g->id] ?? 0)<span class="tag nums">видит {{ $groupSeen[$g->id] }}</span>@endif
                            </span>
                        </span>
                        <x-ui.chevron/>
                    </a>
                @endforeach
                <div data-controller="sheet" class="contents">
                    <button type="button" class="row w-full text-left" data-action="sheet#open">
                        <x-ui.row-icon name="plus" tone="accent"/>
                        <span class="min-w-0 flex-1 font-medium">Новая группа</span>
                    </button>
                    <x-ui.sheet id="group-new" title="Новая группа" :open="$errors->has('name')">
                        <form method="post" action="/buyers/groups" class="flex flex-col gap-4">
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
        @if (!$term || $buyers->isNotEmpty())<h2 class="list-head">Покупатели @if ($buyers->total())<span class="nums">{{ $buyers->total() }}</span>@endif</h2>@endif
        @if ($buyers->isEmpty())
            @if ($term)
                <x-ui.empty href="/buyers" link="Все покупатели">Никого не нашлось</x-ui.empty>
            @else
                <x-ui.empty href="/account/invites" link="Сделать ссылку">Покупатели приходят по вашей ссылке</x-ui.empty>
            @endif
        @else
            <div class="list">
                @foreach ($buyers as $buyer)
                    <div class="row relative" data-search-row>
                        <a href="/buyers/{{ $buyer->id }}" class="absolute inset-0 rounded-(--radius-l)" aria-label="{{ $buyer->name }}"></a>
                        <x-ui.avatar :user="$buyer" :size="44"/>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate font-medium">{{ $buyer->name }}</span>
                            <span class="row-sub">
                                @if ($buyer->login)<span class="tag nums">{{ $buyer->login }}</span>@endif
                                @if ($buyer->phone)<span class="tag nums">{{ $buyer->phoneFormatted() }}</span>@endif
                                @foreach ($buyer->groups as $g)<a href="/buyers/groups/{{ $g->id }}" class="tag relative z-10">{{ $g->name }}</a>@endforeach
                            </span>
                        </span>
                        {{-- Числа — столбиком, как цена и состояние в сделках. --}}
                        <span class="flex shrink-0 flex-col items-end gap-1.5">
                            @if ($interests[$buyer->id] ?? 0)<span class="nums text-sm font-medium text-accent-text">интерес {{ $interests[$buyer->id] }}</span>@endif
                            @if ($seen[$buyer->id] ?? 0)<span class="nums text-sm text-ink-muted">видит {{ $seen[$buyer->id] }}</span>@endif
                        </span>
                        <x-ui.chevron/>
                    </div>
                @endforeach
            </div>
            <x-ui.pager :of="$buyers" :sizes="[]"/>
        @endif
    </section>
    </div>

    {{-- На телефоне — ещё и плашка внизу; на компьютере хватает строки в списке. --}}
    <div class="contents md:hidden">
        <x-ui.action-bar>
            <a href="/account/invites" class="btn btn-accent min-w-0 flex-1"><x-ui.icon name="link" class="size-5"/> Пригласить</a>
        </x-ui.action-bar>
    </div>
</div>
</x-ui.shell>
