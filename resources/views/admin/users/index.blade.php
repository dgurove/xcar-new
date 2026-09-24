@php use App\Users\Role; use App\Http\Admin\UserController; $me = auth()->user(); $link = session('password_link'); $base = UserController::base(); @endphp
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
        <div class="list mt-6">
            @foreach ($users as $user)
                <div class="row" data-controller="sheet">
                    <x-ui.avatar :user="$user" :size="36"/>
                    <div class="min-w-0 flex-1">
                        <a href="{{ $base }}/{{ $user->id }}" class="block truncate">{{ $user->name }}</a>
                        {{-- Всё о человеке одной строкой текста: роль, чей покупатель, логин, телефон, почта, покупатели. --}}
                        <div class="row-sub">
                            <span class="{{ $user->isAdmin() ? 'text-accent-text' : '' }}">{{ mb_strtolower($user->role->label()) }}</span>
                            @if ($user->isBuyer() && $user->manager)<a href="{{ $base }}?preset=buyers&manager={{ $user->manager_id }}" class="text-ink">{{ $user->manager->shortName() }}</a>@endif
                            @if ($user->login)<span>{{ $user->login }}</span>@endif
                            @if ($user->phone)<a href="tel:+{{ $user->phone }}" class="nums">{{ $user->phoneFormatted() }}</a>@endif
                            @if ($user->email)<a href="mailto:{{ $user->email }}">{{ $user->email }}</a>@endif
                            @if ($user->isManager() && isset($user->buyers_count))<a href="{{ $base }}?preset=buyers&manager={{ $user->id }}" class="text-ink">{{ $user->buyers_count }} {{ \App\Support\Plural::of($user->buyers_count, ['покупатель', 'покупателя', 'покупателей']) }}</a>@endif
                            @if ($user->isPending() || $user->isBuyer())<span class="nums">с {{ $user->created_at->translatedFormat('j M') }}</span>@endif
                            {{-- Сотрудник или менеджер пришёл по одноразовой ссылке — чьей: без этого непонятно, кто его позвал. --}}
                            @if (! $user->isBuyer() && $user->invite?->creator)<span>по ссылке {{ $user->invite->creator->shortName() }}</span>@endif
                        </div>
                    </div>
                    @if ($user->isPending())<x-ui.pill tone="urgent" class="!min-h-0 shrink-0 !py-0.5 text-xs">Ждёт</x-ui.pill>@elseif ($user->isRejected())<x-ui.pill tone="danger" class="!min-h-0 shrink-0 !py-0.5 text-xs">Отклонён</x-ui.pill>@endif
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
