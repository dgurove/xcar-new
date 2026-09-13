@php use App\Users\{Role, Section}; $me = auth()->user(); @endphp
<x-ui.shell title="Пользователи" :count="$users->total()">
    <x-ui.toolbar :pills="\App\Http\Admin\UserController::PRESETS" :pill="$preset" pill-param="preset" :counts="$counts" name="users">
        <x-slot:extra>
            <div data-controller="sheet" class="contents">
                <button type="button" class="btn btn-s btn-accent shrink-0 rounded-full" data-action="sheet#open"><x-ui.icon name="plus" class="size-4"/><span class="hidden sm:inline">Пользователь</span></button>
                <x-ui.sheet id="user-new" title="Новый пользователь">
                    <form method="post" action="/nastroyki/polzovateli" class="flex flex-col gap-4">
                        @csrf
                        <x-ui.field name="name" label="Имя" required autofocus/>
                        <x-ui.field name="phone" label="Телефон" type="tel" required/>
                        <x-ui.field name="email" label="Почта" type="email"/>
                        <x-ui.field name="role" label="Роль" :options="collect(Role::cases())->mapWithKeys(fn ($r) => [$r->value => $r->label()])" value="moderator"/>
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
            <input name="q" value="{{ request('q') }}" placeholder="Имя, телефон, почта" class="field-input field-s">
        </x-slot:filters>
    </x-ui.toolbar>

    @if ($users->isEmpty())
        <x-ui.empty class="mt-6">Никого нет.</x-ui.empty>
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
                            <a href="tel:+{{ $user->phone }}" class="tag nums">{{ $user->phoneFormatted() }}</a>
                            @if ($user->email)<a href="mailto:{{ $user->email }}" class="tag">{{ $user->email }}</a>@endif
                            @if ($user->isPending())<span class="tag nums">с {{ $user->created_at->translatedFormat('j M, H:i') }}</span>@endif
                        </div>
                    </div>
                    @if (!$user->isApproved() && !$user->is($me))
                        <form method="post" action="/nastroyki/polzovateli/{{ $user->id }}/dostup" class="flex items-center gap-1.5">
                            @csrf
                            <select name="role" class="field-input field-s !w-auto" aria-label="Роль">@foreach (Role::cases() as $r)<option value="{{ $r->value }}" @selected($r === Role::Manager)>{{ $r->label() }}</option>@endforeach</select>
                            <x-ui.button size="sm">Открыть</x-ui.button>
                            @unless ($user->isRejected())<x-ui.button size="sm" variant="ghost" name="reject" value="1" data-turbo-confirm="Отклонить {{ $user->name }}?">Отклонить</x-ui.button>@endunless
                        </form>
                    @endif
                    <button type="button" class="btn btn-ghost btn-s px-2" data-action="sheet#open" aria-label="Изменить"><x-ui.icon name="edit" class="size-5"/></button>
                    <x-ui.sheet id="user-{{ $user->id }}" :title="$user->name">
                        <form method="post" action="/nastroyki/polzovateli/{{ $user->id }}" class="flex flex-col gap-4">
                            @csrf @method('put')
                            <x-ui.field name="name" label="Имя" :value="$user->name" required/>
                            <x-ui.field name="phone" label="Телефон" type="tel" :value="$user->phoneFormatted()" required/>
                            <x-ui.field name="email" label="Почта" type="email" :value="$user->email"/>
                            <x-ui.field name="role" label="Роль" :options="collect(Role::cases())->mapWithKeys(fn ($r) => [$r->value => $r->label()])" :value="$user->role->value" :disabled="$user->is($me)"/>
                            <div class="flex flex-wrap gap-x-6 gap-y-2">
                                <x-ui.check name="park" :checked="in_array(Section::Park->value, $user->access ?? [], true)">Стоянка</x-ui.check>
                                <x-ui.check name="mail" :checked="$user->wantsMail()">Письма о событиях</x-ui.check>
                            </div>
                            <x-ui.button block>Сохранить</x-ui.button>
                        </form>
                    </x-ui.sheet>
                </div>
            @endforeach
        </div>
        <div class="mt-8">{{ $users->links() }}</div>
    @endif
</x-ui.shell>
