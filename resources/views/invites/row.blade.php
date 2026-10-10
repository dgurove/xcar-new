{{-- Строка ссылки и её шторка. Ожидает $invite, $admin, $base, $fresh, $me. --}}
@php
    $came = $invite->buyers;
    $other = $invite->creator && $invite->creator->isNot($me) && $invite->creator->isNot($invite->manager);
@endphp
<div data-controller="sheet" class="contents">
    <button type="button" class="row w-full text-left transition-colors hover:bg-hover" data-action="sheet#open">
        <x-ui.row-icon :name="! $invite->forBuyer() ? 'user' : 'link'" :tone="$invite->isActive() ? 'soft' : 'plain'"/>
        <span class="min-w-0 flex-1">
            <span class="block truncate font-medium">{{ $invite->title() }}</span>
            <span class="row-sub">
                @if (! $invite->forBuyer())
                    {{-- Одноразовая: кто сделал (админ видит ссылки всех админов — по ней ясно, чей новичок),
                         когда, «→ кто пришёл» или до когда действует. Одноразовость — пилюлей справа. --}}
                    @if ($invite->creator)<x-ui.person :user="$invite->creator" full/>@endif
                    <span class="tag nums">{{ $invite->created_at->translatedFormat('j M H:i') }}</span>
                    @if ($came->isNotEmpty())<x-ui.person :user="$came->first()" full prefix="→"/>
                    @elseif ($invite->expires_at)<span class="tag nums">до {{ $invite->expires_at->translatedFormat('j M H:i') }}</span>@endif
                @else
                    @if ($admin && $invite->label)<span class="tag">{{ $invite->kind() }}</span>@endif
                    @if ($admin && $invite->manager)<x-ui.person :user="$invite->manager"/>@endif
                    @if ($invite->group)<span class="tag">→ {{ $invite->group->name }}</span>@endif
                    @if ($other)<span class="tag">от {{ $invite->creator->shortName() }}</span>@endif
                    @if ($came->isNotEmpty())<span class="tag nums">пришло {{ $came->count() }}</span>@endif
                    @if ($invite->isActive() && $came->isEmpty() && !$invite->group && !$other && !($admin && $invite->manager))<span class="tag nums">{{ $invite->created_at->translatedFormat('j M') }}</span>@endif
                @endif
            </span>
        </span>
        <span class="flex shrink-0 items-center gap-2">
            @if ($invite->isUsedUp())<x-ui.state tone="open">сработала</x-ui.state>
            @elseif ($invite->isExpired())<x-ui.state tone="closed">истекла</x-ui.state>
            @elseif (!$invite->isActive())<x-ui.state tone="closed">выключена</x-ui.state>
            @elseif (! $invite->forBuyer())<x-ui.state tone="urgent">одноразовая</x-ui.state>@endif
            <x-ui.icon name="chevron-right" class="size-5 text-ink-dim"/>
        </span>
    </button>
    <x-ui.sheet id="invite-{{ $invite->id }}" :title="$invite->title()" :open="$fresh === $invite->id">
        {{-- Шапка шторки — фразой: кому ссылка, в какую группу, одноразовая ли и до когда, что спрашиваем, когда и кто сделал
             (10.10.2026: «что-то чипами, что-то текстом» — тут кодов нет, всё факты). --}}
        <div class="detail-line !mt-0 text-sm">
            <span>{{ $invite->kind() }}</span>
            @if ($invite->manager)<span>от {{ $invite->manager->shortName() }}</span>@endif
            @if ($invite->group)<span>в группу «{{ $invite->group->name }}»</span>@endif
            @if (! $invite->forBuyer())<span class="text-urgent">одноразовая</span>@if ($invite->expires_at)<span class="nums">до {{ $invite->expires_at->translatedFormat('j M H:i') }}</span>@endif
            @else<span class="text-ink-muted">{{ $invite->contactFields() === [] ? 'только имя и логин' : 'спрашиваем: '.collect($invite->contactFields())->map(fn ($f) => mb_strtolower(\App\Users\Invite::FIELDS[$f]))->implode(', ') }}</span>@endif
            <span class="nums text-ink-muted">{{ $invite->created_at->translatedFormat(! $invite->forBuyer() ? 'j M H:i' : 'j M') }}@if ($invite->creator && (! $invite->forBuyer() || $invite->creator->isNot($me))) {{ $invite->creator->shortName() }}@endif</span>
        </div>

        @if ($invite->isActive())
            {{-- Готовый текст для мессенджера: одноразовость и срок в нём уже сказаны. --}}
            <x-ui.copy-link :url="$invite->url()" title="Приглашение в xcar" :message="$invite->message()" class="mt-5"/>
        @elseif ($invite->isUsedUp())
            <p class="mt-5 text-ink-muted">Ссылка сработала</p>
        @elseif ($invite->isExpired())
            <p class="mt-5 text-ink-muted">Срок вышел {{ $invite->expires_at->translatedFormat('j M H:i') }}</p>
        @else
            <p class="mt-5 text-ink-muted">Ссылка выключена</p>
        @endif

        @if ($came->isNotEmpty())
            <h3 class="list-head mt-3">Пришли по ссылке</h3>
            <div class="list">
                @foreach ($came as $person)
                    @php($href = $person->isBuyer() && $person->manager_id === $me->id ? '/buyers/'.$person->id : null)
                    <{{ $href ? 'a' : 'div' }} @if ($href) href="{{ $href }}" @endif class="row">
                        <x-ui.avatar :user="$person" :size="40"/>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate font-medium">{{ $person->name }}</span>
                            <span class="row-sub"><span class="tag">{{ $person->rolesLabel() }}</span><span class="tag nums">{{ $person->created_at->translatedFormat('j M') }}</span></span>
                        </span>
                        @if ($href)<x-ui.chevron/>@endif
                    </{{ $href ? 'a' : 'div' }}>
                @endforeach
            </div>
        @endif

        @if ($invite->isActive())
            <form method="post" action="{{ $base }}/{{ $invite->code }}/off" class="mt-6" data-turbo-confirm="Выключить ссылку?" data-turbo-confirm-text="Кто уже пришёл, останется, новые по ней не пройдут">
                @csrf
                <x-ui.button block variant="ghost">Выключить ссылку</x-ui.button>
            </form>
        @elseif (!$invite->isUsedUp() && !$invite->isExpired())
            <form method="post" action="{{ $base }}/{{ $invite->code }}/on" class="mt-6">
                @csrf
                <x-ui.button block variant="secondary">Включить снова</x-ui.button>
            </form>
        @endif
    </x-ui.sheet>
</div>
