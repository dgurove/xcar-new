{{-- Пригласительные ссылки: строка — ссылка со своими условиями; нажатие — шторка с адресом, копированием и выключателем. --}}
<x-ui.cabinet title="Приглашения" :trail="[['Главная', '/'], ['Кабинет', '/lk'], ['Покупатели', '/lk/pokupateli'], ['Приглашения']]">
    <x-slot:actions>
        <div data-controller="sheet" class="contents">
            <button type="button" class="btn btn-s btn-accent rounded-full" data-action="sheet#open"><x-ui.icon name="plus" class="size-4"/> Ссылка</button>
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
        </div>
    </x-slot:actions>

    @if ($invites->isEmpty())
        <x-ui.empty>Ссылок пока нет. Создайте ссылку и отправьте покупателю — по ней он зарегистрируется и будет привязан к вам.</x-ui.empty>
    @else
        <div class="flex flex-col gap-2">
            @foreach ($invites as $invite)
                <div class="row" data-controller="sheet">
                    <button type="button" class="contents text-left" data-action="sheet#open">
                    <span class="flex size-10 shrink-0 items-center justify-center rounded-full {{ $invite->isActive() ? 'bg-accent-soft text-accent-text' : 'bg-surface-3 text-ink-dim' }}"><x-ui.icon name="link" class="size-5"/></span>
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-x-2 gap-y-0.5">
                            <span class="truncate font-medium">{{ $invite->title() }}</span>
                            @unless ($invite->isActive())<x-ui.pill tone="closed" class="!min-h-0 !py-0.5 text-xs">выключена</x-ui.pill>@endunless
                        </div>
                        <div class="mt-1 flex flex-wrap items-center gap-1.5">
                            @foreach ($invite->contactFields() as $f)<span class="tag">{{ mb_strtolower(\App\Users\Invite::FIELDS[$f]) }}</span>@endforeach
                            @if ($invite->contactFields() === [])<span class="tag">только имя и логин</span>@endif
                            @if ($invite->group)<span class="tag">→ {{ $invite->group->name }}</span>@endif
                            <span class="tag nums">{{ $invite->created_at->translatedFormat('j M') }}</span>
                        </div>
                    </div>
                    <span class="nums shrink-0 text-sm text-ink-muted">{{ $invite->buyers_count ? 'пришло '.$invite->buyers_count : '—' }}</span>
                    <x-ui.icon name="chevron-right" class="size-5 shrink-0 text-ink-dim"/>
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
