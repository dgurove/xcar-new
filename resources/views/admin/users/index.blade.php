@php use App\Users\{Role, Section}; use App\Http\Admin\UserController; $me = auth()->user(); $link = session('password_link'); @endphp
{{-- Пользователи и пригласительные ссылки: «Ссылка» — менеджеру (одноразовая, пришедший сразу менеджер)
     или покупателю от имени выбранного менеджера; пилюля «Ссылки» — все ссылки, и менеджерские тоже. --}}
<x-ui.shell title="Пользователи" :count="$preset === 'invites' ? $invites->count() : $users->total()">
    <x-ui.toolbar :pills="$pills" :pill="$preset" pill-param="preset" :counts="$counts" name="users">
        <x-slot:extra>
            <div data-controller="sheet" class="contents">
                <button type="button" class="btn btn-s btn-quiet shrink-0 rounded-full" data-action="sheet#open"><x-ui.icon name="link" class="size-4"/><span class="hidden sm:inline">Ссылка</span></button>
                <x-ui.sheet id="invite-new" title="Пригласительная ссылка" :open="$errors->has('role') || $errors->has('manager_id')">
                    <form method="post" action="/nastroyki/polzovateli/priglasheniya" class="invite-form flex flex-col gap-4">
                        @csrf
                        <div class="flex rounded-(--radius-m) bg-surface-3 p-1">
                            @foreach (['manager' => 'Менеджеру', 'buyer' => 'Покупателю'] as $opt => $text)
                                <label class="tri flex-1"><input type="radio" name="role" value="{{ $opt }}" class="sr-only" @checked(old('role', 'manager') === $opt)><span class="block rounded-(--radius-s) py-2 text-center text-sm text-ink-muted">{{ $text }}</span></label>
                            @endforeach
                        </div>
                        <x-ui.field name="label" label="Название" maxlength="60" placeholder="Кому: имя, компания, выставка…"/>
                        <div data-buyer class="flex flex-col gap-4">
                            <x-ui.field name="manager_id" label="Менеджер" :options="$managers->mapWithKeys(fn ($m) => [$m->id => $m->name])" placeholder="Выберите менеджера"/>
                            <div class="flex flex-col gap-2">
                                <x-ui.check name="phone">Покупатель указывает телефон</x-ui.check>
                                <x-ui.check name="email">Покупатель указывает почту</x-ui.check>
                            </div>
                        </div>
                        <x-ui.button block>Создать ссылку</x-ui.button>
                    </form>
                </x-ui.sheet>
            </div>
            <div data-controller="sheet" class="contents">
                <button type="button" class="btn btn-s btn-accent shrink-0 rounded-full" data-action="sheet#open"><x-ui.icon name="plus" class="size-4"/><span class="hidden sm:inline">Пользователь</span></button>
                <x-ui.sheet id="user-new" title="Новый пользователь">
                    <form method="post" action="/nastroyki/polzovateli" class="flex flex-col gap-4">
                        @csrf
                        <x-ui.field name="name" label="Имя" required autofocus/>
                        <x-ui.field name="phone" label="Телефон" type="tel"/>
                        <x-ui.field name="email" label="Почта" type="email"/>
                        <x-ui.field name="login" label="Логин" autocapitalize="none" placeholder="если нет телефона и почты"/>
                        <x-ui.field name="role" label="Роль" :options="collect(UserController::CREATABLE)->mapWithKeys(fn ($r) => [$r->value => $r->label()])" value="moderator"/>
                        <div class="flex flex-wrap gap-x-6 gap-y-2">
                            <x-ui.check name="park">Стоянка</x-ui.check>
                            <x-ui.check name="mail" checked>Письма о событиях</x-ui.check>
                        </div>
                        <x-ui.button block>Добавить</x-ui.button>
                    </form>
                </x-ui.sheet>
            </div>
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
        @if ($invites->isEmpty())
            <x-ui.empty class="mt-6">Ссылок пока нет.</x-ui.empty>
        @else
            <div class="mt-6 grid gap-2 xl:grid-cols-2">
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
                                    @if ($invite->creator && $invite->creator->isStaff())<span class="tag">от {{ $invite->creator->shortName() }}</span>@endif
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
                                <form method="post" action="/nastroyki/polzovateli/priglasheniya/{{ $invite->code }}/vykl" class="mt-6" data-turbo-confirm="Выключить ссылку?">
                                    @csrf
                                    <x-ui.button block variant="ghost">Выключить ссылку</x-ui.button>
                                </form>
                            @else
                                <p class="text-ink-muted">Ссылка выключена: по ней больше нельзя зарегистрироваться.</p>
                                <form method="post" action="/nastroyki/polzovateli/priglasheniya/{{ $invite->code }}/vkl" class="mt-6">
                                    @csrf
                                    <x-ui.button block variant="secondary">Включить снова</x-ui.button>
                                </form>
                            @endif
                        </x-ui.sheet>
                    </div>
                @endforeach
            </div>
        @endif
    @elseif ($users->isEmpty())
        <x-ui.empty class="mt-6">{{ $preset === 'buyers' ? 'Покупателей пока нет — они приходят по ссылкам менеджеров.' : 'Никого нет.' }}</x-ui.empty>
    @else
        <div class="mt-6 flex flex-col gap-2">
            @foreach ($users as $user)
                <div class="row" data-controller="sheet">
                    <x-ui.avatar :user="$user" :size="40"/>
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-x-2 gap-y-0.5">
                            <span class="truncate font-medium">{{ $user->name }}</span>
                            <x-ui.pill :tone="$user->isAdmin() ? 'soft' : ($user->isStaff() ? 'plain' : 'closed')" class="!min-h-0 !py-0.5 text-xs">{{ $user->role->label() }}</x-ui.pill>
                            @if ($user->canAccess(Section::Park) && !$user->isAdmin())<span class="chip text-xs">Стоянка</span>@endif
                            @if ($user->isPending())<x-ui.pill tone="urgent" class="!min-h-0 !py-0.5 text-xs">Ждёт</x-ui.pill>@elseif ($user->isRejected())<x-ui.pill tone="danger" class="!min-h-0 !py-0.5 text-xs">Отклонён</x-ui.pill>@endif
                        </div>
                        <div class="mt-1 flex flex-wrap items-center gap-1.5">
                            @if ($user->isBuyer() && $user->manager)<a href="/nastroyki/polzovateli?preset=buyers&manager={{ $user->manager_id }}" class="chip person"><x-ui.avatar :user="$user->manager" :size="20"/>{{ $user->manager->shortName() }}</a>@endif
                            @if ($user->login)<span class="tag nums">{{ $user->login }}</span>@endif
                            @if ($user->phone)<a href="tel:+{{ $user->phone }}" class="tag nums">{{ $user->phoneFormatted() }}</a>@endif
                            @if ($user->email)<a href="mailto:{{ $user->email }}" class="tag">{{ $user->email }}</a>@endif
                            @if ($user->isManager() && isset($user->buyers_count))<a href="/nastroyki/polzovateli?preset=buyers&manager={{ $user->id }}" class="tag">{{ $user->buyers_count }} {{ \App\Support\Plural::of($user->buyers_count, ['покупатель', 'покупателя', 'покупателей']) }}</a>@endif
                            @if ($user->isPending() || $user->isBuyer())<span class="tag nums">с {{ $user->created_at->translatedFormat('j M') }}</span>@endif
                        </div>
                    </div>
                    @if (!$user->isApproved() && !$user->is($me))
                        <form method="post" action="/nastroyki/polzovateli/{{ $user->id }}/dostup" class="flex items-center gap-1.5">
                            @csrf
                            <select name="role" class="field-input field-s !w-auto" aria-label="Роль">@foreach (UserController::CREATABLE as $r)<option value="{{ $r->value }}" @selected($r === Role::Manager)>{{ $r->label() }}</option>@endforeach</select>
                            <x-ui.button size="sm">Открыть</x-ui.button>
                            @unless ($user->isRejected())<x-ui.button size="sm" variant="ghost" name="reject" value="1" data-turbo-confirm="Отклонить {{ $user->name }}?">Отклонить</x-ui.button>@endunless
                        </form>
                    @endif
                    <button type="button" class="btn btn-ghost btn-s px-2" data-action="sheet#open" aria-label="Изменить"><x-ui.icon name="edit" class="size-5"/></button>
                    <x-ui.sheet id="user-{{ $user->id }}" :title="$user->name" :open="($link['user'] ?? null) === $user->id">
                        @if (($link['user'] ?? null) === $user->id)
                            <x-ui.copy-link :url="$link['url']" title="Ссылка для нового пароля" class="mb-6">
                                <p class="text-sm text-ink-muted">Действует сутки, один раз. Отдайте её {{ $user->shortName() }} любым способом.</p>
                            </x-ui.copy-link>
                        @endif
                        <form method="post" action="/nastroyki/polzovateli/{{ $user->id }}" class="flex flex-col gap-4">
                            @csrf @method('put')
                            <x-ui.field name="name" label="Имя" :value="$user->name" required/>
                            @if ($user->isBuyer())
                                <div class="flex flex-wrap gap-1.5">
                                    @if ($user->login)<span class="tag nums">{{ $user->login }}</span>@endif
                                    @if ($user->phone)<span class="tag nums">{{ $user->phoneFormatted() }}</span>@endif
                                    @if ($user->email)<span class="tag">{{ $user->email }}</span>@endif
                                </div>
                                <x-ui.field name="manager_id" label="Менеджер" :options="$managers->mapWithKeys(fn ($m) => [$m->id => $m->name])" :value="$user->manager_id"/>
                            @else
                                <x-ui.field name="phone" label="Телефон" type="tel" :value="$user->phoneFormatted()"/>
                                <x-ui.field name="email" label="Почта" type="email" :value="$user->email"/>
                                <x-ui.field name="login" label="Логин" :value="$user->login" autocapitalize="none"/>
                                <x-ui.field name="role" label="Роль" :options="collect(UserController::CREATABLE)->mapWithKeys(fn ($r) => [$r->value => $r->label()])" :value="$user->role->value" :disabled="$user->is($me)"/>
                                <div class="flex flex-wrap gap-x-6 gap-y-2">
                                    <x-ui.check name="park" :checked="in_array(Section::Park->value, $user->access ?? [], true)">Стоянка</x-ui.check>
                                    <x-ui.check name="mail" :checked="$user->wantsMail()">Письма о событиях</x-ui.check>
                                </div>
                            @endif
                            <x-ui.button block>Сохранить</x-ui.button>
                        </form>
                        @unless ($user->is($me))
                            <form method="post" action="/nastroyki/polzovateli/{{ $user->id }}/parol" class="mt-3"
                                data-turbo-confirm="Выдать ссылку для нового пароля?" data-turbo-confirm-label="Выдать"
                                data-turbo-confirm-text="{{ $user->name }} откроет её и придумает пароль сам. Прежние ссылки погаснут, текущий пароль пока действует.">
                                @csrf
                                <x-ui.button type="submit" variant="secondary" block><x-ui.icon name="link" class="size-5"/> Ссылка для нового пароля</x-ui.button>
                            </form>
                        @endunless
                    </x-ui.sheet>
                </div>
            @endforeach
        </div>
        <div class="mt-8">{{ $users->links() }}</div>
    @endif
</x-ui.shell>
