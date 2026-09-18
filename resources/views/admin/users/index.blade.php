@php use App\Users\{Role, Section}; use App\Http\Admin\UserController; $me = auth()->user(); $link = session('password_link'); $base = UserController::base(); @endphp
{{-- Пользователи; пилюля «Ссылки» — все пригласительные ссылки тем же списком, что в кабинете
     (x-invites.list): первая строка — новая, менеджеру или покупателю от имени менеджера. --}}
<x-ui.cabinet title="Пользователи">
    <x-ui.toolbar :pills="$pills" :pill="$preset" pill-param="preset" :counts="$counts" name="users">
        <x-slot:extra>
            {{-- Руками людей не заводят: только пригласительной ссылкой — кнопка ведёт к ним. --}}
            @if ($preset !== 'invites')<a href="{{ \App\Http\Cabinet\InviteController::home() }}" class="btn btn-s btn-accent shrink-0 rounded-full" data-turbo-action="replace"><x-ui.icon name="link" class="size-4"/><span class="hidden sm:inline">Пригласить</span></a>@endif
        </x-slot:extra>
        <x-slot:filters>
            <input name="q" value="{{ request('q') }}" placeholder="Имя, логин, телефон, почта" class="field-input field-s">
            @if ($preset === 'buyers')
                <select name="manager" class="field-input field-s" aria-label="Менеджер">
                    <option value="">Все менеджеры</option>
                    @foreach ($managers as $m)<option value="{{ $m->id }}" @selected((int) request('manager') === $m->id)>{{ $m->name }}</option>@endforeach
                </select>
            @endif
        </x-slot:filters>
    </x-ui.toolbar>

    @if ($preset === 'invites')
        <div class="mt-6"><x-invites.list :invites="$invites" admin :managers="$managers" :fresh="$fresh"/></div>
    @elseif ($users->isEmpty())
        <x-ui.empty class="mt-6">{{ $preset === 'buyers' ? 'Покупателей пока нет — они приходят по ссылкам менеджеров.' : 'Никого нет.' }}</x-ui.empty>
    @else
        <div class="mt-6 flex flex-col gap-2">
            @foreach ($users as $user)
                <div class="row" data-controller="sheet">
                    <x-ui.avatar :user="$user" :size="40"/>
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-x-2 gap-y-0.5">
                            <a href="{{ $base }}/{{ $user->id }}" class="truncate font-medium">{{ $user->name }}</a>
                            <x-ui.pill :tone="$user->isAdmin() ? 'soft' : ($user->isStaff() ? 'plain' : 'closed')" class="!min-h-0 !py-0.5 text-xs">{{ $user->role->label() }}</x-ui.pill>
                            @if ($user->canAccess(Section::Park) && !$user->isAdmin())<span class="chip text-xs">Стоянка</span>@endif
                            @if ($user->isPending())<x-ui.pill tone="urgent" class="!min-h-0 !py-0.5 text-xs">Ждёт</x-ui.pill>@elseif ($user->isRejected())<x-ui.pill tone="danger" class="!min-h-0 !py-0.5 text-xs">Отклонён</x-ui.pill>@endif
                        </div>
                        <div class="mt-1 flex flex-wrap items-center gap-1.5">
                            @if ($user->isBuyer() && $user->manager)<a href="{{ $base }}?preset=buyers&manager={{ $user->manager_id }}" class="chip person"><x-ui.avatar :user="$user->manager" :size="20"/>{{ $user->manager->shortName() }}</a>@endif
                            @if ($user->login)<span class="tag nums">{{ $user->login }}</span>@endif
                            @if ($user->phone)<a href="tel:+{{ $user->phone }}" class="tag nums">{{ $user->phoneFormatted() }}</a>@endif
                            @if ($user->email)<a href="mailto:{{ $user->email }}" class="tag">{{ $user->email }}</a>@endif
                            @if ($user->isManager() && isset($user->buyers_count))<a href="{{ $base }}?preset=buyers&manager={{ $user->id }}" class="tag">{{ $user->buyers_count }} {{ \App\Support\Plural::of($user->buyers_count, ['покупатель', 'покупателя', 'покупателей']) }}</a>@endif
                            @if ($user->isPending() || $user->isBuyer())<span class="tag nums">с {{ $user->created_at->translatedFormat('j M') }}</span>@endif
                            {{-- Сотрудник или менеджер пришёл по одноразовой ссылке — чьей: без этого непонятно, кто его позвал. --}}
                            @if (! $user->isBuyer() && $user->invite?->creator)<x-ui.person :user="$user->invite->creator" full prefix="по ссылке"/>@endif
                        </div>
                    </div>
                    @if (!$user->isApproved() && !$user->is($me))
                        <x-admin.user-access :user="$user" :base="$base"/>
                    @endif
                    <button type="button" class="btn btn-ghost btn-s px-2" data-action="sheet#open" aria-label="Изменить"><x-ui.icon name="edit" class="size-5"/></button>
                    <x-admin.user-sheet :user="$user" :managers="$managers" :link="$link" :base="$base" :me="$me"/>
                </div>
            @endforeach
        </div>
        <div class="mt-8"><x-ui.pager :of="$users"/></div>
    @endif
</x-ui.cabinet>
