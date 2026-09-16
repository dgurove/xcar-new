{{-- Строка ссылки и её шторка. Ожидает $invite, $admin, $base, $fresh, $me. --}}
@php
    $came = $invite->buyers;
    $other = $invite->creator && $invite->creator->isNot($me) && $invite->creator->isNot($invite->manager);
@endphp
<div data-controller="sheet" class="contents">
    <button type="button" class="row w-full text-left transition-colors hover:bg-hover" data-action="sheet#open">
        <span class="flex size-11 shrink-0 items-center justify-center rounded-full {{ $invite->isActive() ? 'bg-accent-soft text-accent-text' : 'bg-surface-3 text-ink-dim' }}"><x-ui.icon :name="$invite->forManager() ? 'user' : 'link'" class="size-5"/></span>
        <span class="min-w-0 flex-1">
            <span class="block truncate font-medium">{{ $invite->title() }}</span>
            <span class="row-sub">
                @if ($admin && $invite->label)<span class="tag">{{ $invite->kind() }}</span>@endif
                @if ($invite->forManager() && $came->isNotEmpty())
                    <x-ui.person :user="$came->first()"/>
                @elseif ($admin && $invite->manager)
                    <x-ui.person :user="$invite->manager"/>
                @endif
                @if ($invite->group)<span class="tag">→ {{ $invite->group->name }}</span>@endif
                @if ($other)<span class="tag">от {{ $invite->creator->shortName() }}</span>@endif
                @if (!$invite->forManager() && $came->isNotEmpty())<span class="tag nums">пришло {{ $came->count() }}</span>@endif
                @if ($invite->isActive() && $came->isEmpty() && !$invite->group && !$other && !($admin && $invite->manager))<span class="tag nums">{{ $invite->created_at->translatedFormat('j M') }}</span>@endif
            </span>
        </span>
        <span class="flex shrink-0 items-center gap-2">
            @if ($invite->isUsedUp())<x-ui.pill tone="open" class="!min-h-0 !py-1 text-xs">сработала</x-ui.pill>@elseif (!$invite->isActive())<x-ui.pill tone="closed" class="!min-h-0 !py-1 text-xs">выключена</x-ui.pill>@endif
            <x-ui.icon name="chevron-right" class="size-5 text-ink-dim"/>
        </span>
    </button>
    <x-ui.sheet id="invite-{{ $invite->id }}" :title="$invite->title()" :open="$fresh === $invite->id">
        <div class="flex flex-wrap gap-1.5">
            <span class="tag">{{ $invite->kind() }}</span>
            @if ($invite->manager)<x-ui.person :user="$invite->manager"/>@endif
            @if ($invite->group)<span class="tag">→ {{ $invite->group->name }}</span>@endif
            @if ($invite->forManager())<span class="tag">одноразовая</span>@else
                @foreach ($invite->contactFields() as $f)<span class="tag">{{ mb_strtolower(\App\Users\Invite::FIELDS[$f]) }}</span>@endforeach
                @if ($invite->contactFields() === [])<span class="tag">только имя и логин</span>@endif
            @endif
            <span class="tag nums">{{ $invite->created_at->translatedFormat('j M') }}</span>
            @if ($invite->creator && $invite->creator->isNot($me))<span class="tag">от {{ $invite->creator->shortName() }}</span>@endif
        </div>

        @if ($invite->isActive())
            <x-ui.copy-link :url="$invite->url()" title="Приглашение в xcar" class="mt-5"/>
        @elseif ($invite->isUsedUp())
            <p class="mt-5 text-ink-muted">Ссылка сработала и больше не действует.</p>
        @else
            <p class="mt-5 text-ink-muted">Ссылка выключена: по ней больше нельзя зарегистрироваться.</p>
        @endif

        @if ($came->isNotEmpty())
            <h3 class="mt-6 mb-2 text-base font-medium">Пришли по ссылке</h3>
            <div class="flex flex-col gap-2">
                @foreach ($came as $person)
                    @php($href = $person->isBuyer() && $person->manager_id === $me->id ? '/lk/pokupateli/'.$person->id : null)
                    <{{ $href ? 'a' : 'div' }} @if ($href) href="{{ $href }}" @endif class="row">
                        <x-ui.avatar :user="$person" :size="40"/>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate font-medium">{{ $person->name }}</span>
                            <span class="row-sub"><span class="tag">{{ $person->role->label() }}</span><span class="tag nums">{{ $person->created_at->translatedFormat('j M') }}</span></span>
                        </span>
                        @if ($href)<x-ui.icon name="chevron-right" class="size-5 shrink-0 text-ink-dim"/>@endif
                    </{{ $href ? 'a' : 'div' }}>
                @endforeach
            </div>
        @endif

        @if ($invite->isActive())
            <form method="post" action="{{ $base }}/{{ $invite->code }}/vykl" class="mt-6" data-turbo-confirm="Выключить ссылку?" data-turbo-confirm-text="Те, кто уже зарегистрировался, останутся. Новые по ней не пройдут.">
                @csrf
                <x-ui.button block variant="ghost">Выключить ссылку</x-ui.button>
            </form>
        @elseif (!$invite->isUsedUp())
            <form method="post" action="{{ $base }}/{{ $invite->code }}/vkl" class="mt-6">
                @csrf
                <x-ui.button block variant="secondary">Включить снова</x-ui.button>
            </form>
        @endif
    </x-ui.sheet>
</div>
