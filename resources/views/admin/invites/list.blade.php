{{-- Список ссылок админа: строка — ссылка, нажатие — шторка с адресом и выключателем.
     $base — путь для vykl/vkl, $fresh — только что созданная (шторка открыта). --}}
<div class="grid gap-2 xl:grid-cols-2 {{ $class ?? '' }}">
    @foreach ($invites as $invite)
        <div data-controller="sheet" class="contents">
            <button type="button" class="row w-full text-left transition-colors hover:bg-hover" data-action="sheet#open">
                <span class="flex size-10 shrink-0 items-center justify-center rounded-full {{ $invite->isActive() ? 'bg-accent-soft text-accent-text' : 'bg-surface-3 text-ink-dim' }}"><x-ui.icon :name="$invite->forManager() ? 'user' : 'link'" class="size-5"/></span>
                <span class="min-w-0 flex-1">
                    <span class="flex flex-wrap items-center gap-x-2 gap-y-0.5">
                        <span class="truncate font-medium">{{ $invite->label ?: ($invite->forManager() ? 'Менеджер' : 'Покупатель') }}</span>
                        <x-ui.pill :tone="$invite->forManager() ? 'soft' : 'plain'" class="!min-h-0 !py-0.5 text-xs">{{ $invite->forManager() ? 'менеджеру' : 'покупателю' }}</x-ui.pill>
                    </span>
                    <span class="row-sub">
                        <span class="tag nums">{{ $invite->created_at->translatedFormat('j M') }}</span>
                        @if ($invite->manager)<x-ui.person :user="$invite->manager"/>@endif
                        @if ($invite->forManager())<span class="tag">одноразовая</span>@else
                            @foreach ($invite->contactFields() as $f)<span class="tag">{{ mb_strtolower(\App\Users\Invite::FIELDS[$f]) }}</span>@endforeach
                            @if ($invite->contactFields() === [])<span class="tag">только имя и логин</span>@endif
                        @endif
                        @if ($invite->group)<span class="tag">→ {{ $invite->group->name }}</span>@endif
                        @if ($invite->creator && $invite->creator->isStaff() && $invite->creator->isNot(auth()->user()))<span class="tag">от {{ $invite->creator->shortName() }}</span>@endif
                    </span>
                </span>
                <span class="flex shrink-0 items-center gap-2">
                    @if ($invite->isUsedUp())<x-ui.pill tone="open" class="!min-h-0 !py-1 text-xs">сработала</x-ui.pill>@elseif (!$invite->isActive())<x-ui.pill tone="closed" class="!min-h-0 !py-1 text-xs">выключена</x-ui.pill>@endif
                    @if ($invite->buyers_count && !$invite->forManager())<span class="nums text-sm text-ink-muted">пришло {{ $invite->buyers_count }}</span>@endif
                    <x-ui.icon name="chevron-right" class="size-5 text-ink-dim"/>
                </span>
            </button>
            <x-ui.sheet id="invite-{{ $invite->id }}" :title="$invite->title()" :open="$fresh === $invite->id">
                @if ($invite->isUsedUp())
                    <p class="text-ink-muted">По этой ссылке уже зарегистрировались: {{ $invite->buyers->pluck('name')->join(', ') }}.</p>
                @elseif ($invite->isActive())
                    <x-ui.copy-link :url="$invite->url()" title="Приглашение в xcar">
                        @if ($invite->forManager())<p class="text-sm text-ink-muted">Сработает один раз: кто откроет и зарегистрируется — тот и менеджер.</p>@endif
                    </x-ui.copy-link>
                    <form method="post" action="{{ $base }}/{{ $invite->code }}/vykl" class="mt-6" data-turbo-confirm="Выключить ссылку?">
                        @csrf
                        <x-ui.button block variant="ghost">Выключить ссылку</x-ui.button>
                    </form>
                @else
                    <p class="text-ink-muted">Ссылка выключена: по ней больше нельзя зарегистрироваться.</p>
                    <form method="post" action="{{ $base }}/{{ $invite->code }}/vkl" class="mt-6">
                        @csrf
                        <x-ui.button block variant="secondary">Включить снова</x-ui.button>
                    </form>
                @endif
            </x-ui.sheet>
        </div>
    @endforeach
</div>
