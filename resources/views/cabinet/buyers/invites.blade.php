{{-- Пригласительные ссылки: строка — ссылка со своими условиями; нажатие — шторка с адресом,
     копированием и выключателем. «Новая ссылка» — в полосе внизу. --}}
<x-ui.cabinet title="Приглашения" :trail="[['Главная', '/'], ['Кабинет', '/lk'], ['Покупатели', '/lk/pokupateli'], ['Приглашения']]">
    <div data-controller="sheet" class="contents">
        <x-ui.sheet id="invite-new" title="Новая ссылка" :open="$errors->any()">
            <form method="post" action="/lk/pokupateli/priglasheniya" class="flex flex-col gap-4">
                @csrf
                <x-ui.field name="label" label="Название" maxlength="60" placeholder="Кому: дилерам, знакомым, выставка…"/>
                @if ($groups->isNotEmpty())
                    <x-ui.field name="group_id" label="Сразу в группу" :options="$groups->pluck('name', 'id')" placeholder="Без группы"/>
                @endif
                {{-- Что покупатель укажет о себе. По умолчанию — ничего: у нас не будет его контактов, только имя и логин. --}}
                <div class="flex flex-col gap-2 pt-1">
                    <x-ui.check name="phone">Покупатель указывает телефон</x-ui.check>
                    <x-ui.check name="email">Покупатель указывает почту</x-ui.check>
                </div>
                <x-ui.button block>Создать ссылку</x-ui.button>
            </form>
        </x-ui.sheet>
        <x-ui.action-bar>
            <button type="button" class="btn btn-accent min-w-0 flex-1 md:flex-none" data-action="sheet#open"><x-ui.icon name="plus" class="size-5"/> Новая ссылка</button>
        </x-ui.action-bar>
    </div>

    @if ($invites->isEmpty())
        <x-ui.empty>Ссылок пока нет — создайте и отправьте покупателю.</x-ui.empty>
    @else
        <div class="grid gap-2 xl:grid-cols-2">
            @foreach ($invites as $invite)
                <div data-controller="sheet" class="contents">
                    <button type="button" class="row w-full text-left transition-colors hover:bg-hover" data-action="sheet#open">
                        <span class="flex size-11 shrink-0 items-center justify-center rounded-full {{ $invite->isActive() ? 'bg-accent-soft text-accent-text' : 'bg-surface-3 text-ink-dim' }}"><x-ui.icon name="link" class="size-5"/></span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate font-medium">{{ $invite->label ?: 'Ссылка' }}</span>
                            <span class="row-sub">
                                <span class="tag nums">{{ $invite->created_at->translatedFormat('j M') }}</span>
                                @foreach ($invite->contactFields() as $f)<span class="tag">{{ mb_strtolower(\App\Users\Invite::FIELDS[$f]) }}</span>@endforeach
                                @if ($invite->contactFields() === [])<span class="tag">только имя и логин</span>@endif
                                @if ($invite->group)<span class="tag">→ {{ $invite->group->name }}</span>@endif
                            </span>
                        </span>
                        <span class="flex shrink-0 items-center gap-2">
                            @unless ($invite->isActive())<x-ui.pill tone="closed" class="!min-h-0 !py-1 text-xs">выключена</x-ui.pill>@endunless
                            @if ($invite->buyers_count)<span class="nums text-sm text-ink-muted">пришло {{ $invite->buyers_count }}</span>@endif
                            <x-ui.icon name="chevron-right" class="size-5 text-ink-dim"/>
                        </span>
                    </button>
                    <x-ui.sheet id="invite-{{ $invite->id }}" :title="$invite->title()" :open="$fresh === $invite->id">
                        @if ($invite->isActive())
                            <x-ui.copy-link :url="$invite->url()" title="Приглашение в xcar"/>
                            <form method="post" action="/lk/pokupateli/priglasheniya/{{ $invite->code }}/vykl" class="mt-6" data-turbo-confirm="Выключить ссылку?" data-turbo-confirm-text="Те, кто уже зарегистрировался, останутся. Новые по ней не пройдут.">
                                @csrf
                                <x-ui.button block variant="ghost">Выключить ссылку</x-ui.button>
                            </form>
                        @else
                            <p class="text-ink-muted">Ссылка выключена: по ней больше нельзя зарегистрироваться.</p>
                            <form method="post" action="/lk/pokupateli/priglasheniya/{{ $invite->code }}/vkl" class="mt-6">
                                @csrf
                                <x-ui.button block variant="secondary">Включить снова</x-ui.button>
                            </form>
                        @endif
                    </x-ui.sheet>
                </div>
            @endforeach
        </div>
    @endif
</x-ui.cabinet>
