{{-- Список покупателей — один на два входа: «Сделки → Покупатели» (`/buyers`) и кабинет (`/account/buyers`, 12.10.2026,
     владелец: «менеджеры не находят покупателей»). $base — адрес экрана, $invitesUrl — куда «Пригласить». --}}
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
        {{-- На телефоне «Добавить» — плашка внизу экрана; строкой — только на компьютере. Экран ссылок открывается сразу
             шторкой «Покупатель»: ввести данные или сделать ссылку (18.10.2026). --}}
        <div class="contents max-md:hidden"><x-people.pick name="buyer" kind="buyer" only reload link passport placeholder="Добавить покупателя"/></div>
        <a href="{{ $invitesUrl }}" class="row max-md:!hidden">
            <x-ui.row-icon name="link"/>
            <span class="min-w-0 flex-1">Ссылки-приглашения</span>
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
                            @if ($groupSeen[$g->id] ?? 0)<span class="tag nums">открыто {{ $groupSeen[$g->id] }}</span>@endif
                        </span>
                    </span>
                    <x-ui.chevron/>
                </a>
            @endforeach
            <div data-controller="sheet" class="contents">
                <x-ui.sheet-row>
                    <x-ui.row-icon name="plus" tone="accent"/>
                    <span class="min-w-0 flex-1 font-medium">Новая группа</span>
                </x-ui.sheet-row>
                <x-ui.sheet id="group-new" title="Новая группа">
                    <form method="post" action="/buyers/groups" class="flex flex-col gap-4">
                        @csrf
                        <x-ui.field name="name" label="Название" required maxlength="60" placeholder="Дилеры, Казань, VIP…"/>
                        <x-ui.sheet-foot>Создать</x-ui.sheet-foot>
                    </form>
                </x-ui.sheet>
            </div>
        </div>
    </section>
@endunless

<section>
    {{-- С доступом и без (11.11.2026, владелец: «разделить понятия „покупатели с доступом“ и „без доступа“»): с доступом —
         входят и видят предложения, без — только данные для сделок (паспорт), войти не могут. При поиске — все вместе. --}}
    @if ($term)
        @if ($buyers->isNotEmpty())<h2 class="list-head">Покупатели <span class="nums">{{ $buyers->total() }}</span></h2>@endif
    @elseif ($accessCounts['open'] + $accessCounts['none'])
        <x-ui.pills class="mb-3">
            <x-ui.pill :href="$base" :current="$access === 'open'" :tone="$access === 'open' ? 'accent' : null">С доступом <span class="nums opacity-70">{{ $accessCounts['open'] }}</span></x-ui.pill>
            <x-ui.pill :href="$base.'?access=none'" :current="$access === 'none'" :tone="$access === 'none' ? 'accent' : null">Без доступа <span class="nums opacity-70">{{ $accessCounts['none'] }}</span></x-ui.pill>
        </x-ui.pills>
    @endif
    @if ($buyers->isEmpty())
        @if ($term)
            <x-ui.empty :href="$base" link="Все покупатели">Никого не нашлось</x-ui.empty>
        @elseif ($accessCounts['open'] + $accessCounts['none'])
            <x-ui.empty>{{ $access === 'none' ? 'Все ваши покупатели с доступом' : 'Доступ к предложениям пока никому не открыт' }}</x-ui.empty>
        @else
            <x-ui.empty>Покупателей пока нет</x-ui.empty>
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
                            @if ($buyer->phone)<x-ui.tel :value="$buyer->phone" class="tag relative z-10"/>@endif
                            @foreach ($buyer->groups as $g)<a href="/buyers/groups/{{ $g->id }}" class="tag relative z-10">{{ $g->name }}</a>@endforeach
                        </span>
                    </span>
                    {{-- Числа — столбиком, как цена и состояние в сделках. --}}
                    <span class="flex shrink-0 flex-col items-end gap-1.5">
                        @if ($interests[$buyer->id] ?? 0)<span class="nums text-sm font-medium text-accent-text">интерес {{ $interests[$buyer->id] }}</span>@endif
                        {{-- Без доступа ничего не видит — не «видит все» (11.11.2026, владелец); во вкладке «Без доступа» это и так
                             сказано, пометка — только в поиске, где все вместе. --}}
                        @if (! $buyer->hasBuyerAccess())
                            @if ($term)<span class="text-sm text-ink-muted">без доступа</span>@endif
                        @elseif ($buyer->buyerSeesAll())
                            <span class="text-sm text-ink-muted">видит все</span>
                        @elseif ($seen[$buyer->id] ?? 0)
                            <span class="nums text-sm text-ink-muted">открыто {{ $seen[$buyer->id] }}</span>
                        @else
                            <span class="text-sm text-urgent">ничего не открыто</span>
                        @endif
                    </span>
                    <x-ui.chevron/>
                </div>
            @endforeach
        </div>
        <x-ui.pager :of="$buyers" :sizes="[]"/>
    @endif
</section>
</div>

