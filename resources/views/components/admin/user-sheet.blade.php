{{-- Шит «Изменить» человека (админ): имя, у покупателя менеджер, у остальных контакты, роль и доступ;
     ниже ссылка на новый пароль, закрыть доступ или удалить. Открывается сам, если ссылка только что выдана. --}}
@props(['user', 'managers', 'link' => null, 'base', 'me'])
@php use App\Users\{Role, Section}; use App\Http\Admin\UserController; @endphp
<x-ui.sheet id="user-{{ $user->id }}" :title="$user->name" :open="($link['user'] ?? null) === $user->id">
    @if (($link['user'] ?? null) === $user->id)
        <x-ui.copy-link :url="$link['url']" title="Ссылка для нового пароля" class="mb-6">
            <p class="text-sm text-ink-muted">Действует сутки, один раз. Отдайте её {{ $user->shortName() }} любым способом.</p>
        </x-ui.copy-link>
    @endif
    <form method="post" action="{{ $base }}/{{ $user->id }}" class="flex flex-col gap-4">
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
            <x-ui.field name="role" label="Роль" :options="collect(UserController::ROLES)->mapWithKeys(fn ($r) => [$r->value => $r->label()])" :value="$user->role->value" :disabled="$user->is($me)"/>
            <div class="flex flex-wrap gap-x-6 gap-y-2">
                <x-ui.check name="park" :checked="in_array(Section::Park->value, $user->access ?? [], true)">Стоянка</x-ui.check>
                <x-ui.check name="mail" :checked="$user->wantsMail()">Письма о событиях</x-ui.check>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <x-ui.field name="park_yard_id" label="Своя площадка" :options="\App\Park\Yard::orderBy('name')->pluck('name', 'id')" placeholder="Все" :value="$user->park_yard_id"/>
                <x-ui.check name="park_readonly" :checked="$user->park_readonly" class="self-end">Только приёмка</x-ui.check>
            </div>
        @endif
        <x-ui.button block>Сохранить</x-ui.button>
    </form>
    @unless ($user->is($me))
        <form method="post" action="{{ $base }}/{{ $user->id }}/password" class="mt-3"
            data-turbo-confirm="Выдать ссылку для нового пароля?" data-turbo-confirm-label="Выдать"
            data-turbo-confirm-text="{{ $user->name }} откроет её и придумает пароль сам. Прежние ссылки погаснут, текущий пароль пока действует.">
            @csrf
            <x-ui.button type="submit" variant="secondary" block><x-ui.icon name="link" class="size-5"/> Ссылка для нового пароля</x-ui.button>
        </form>
        {{-- Ссылка могла уйти не тому: допущенному — закрыть доступ (выйдет отовсюду, вернуть можно из «Отклонённых»);
             отклонённому без истории — удалить насовсем. --}}
        @if ($user->isApproved())
            <form method="post" action="{{ $base }}/{{ $user->id }}/access" class="mt-3"
                data-turbo-confirm="Закрыть доступ {{ $user->shortName() }}?" data-turbo-confirm-label="Закрыть"
                data-turbo-confirm-text="{{ $user->isManager() ? 'Выйдет со всех устройств, его ссылки и покупатели закроются. Сделки останутся в истории' : 'Выйдет со всех устройств и не сможет войти' }}">
                @csrf<input type="hidden" name="reject" value="1">
                <x-ui.button type="submit" variant="danger" block>Закрыть доступ</x-ui.button>
            </form>
        @elseif ($user->isRejected() && ! UserController::traces($user))
            <form method="post" action="{{ $base }}/{{ $user->id }}" class="mt-3"
                data-turbo-confirm="Удалить {{ $user->shortName() }} насовсем?" data-turbo-confirm-label="Удалить" data-turbo-confirm-text="Данных о нём не останется">
                @csrf @method('delete')
                <x-ui.button type="submit" variant="danger" block>Удалить</x-ui.button>
            </form>
        @endif
    @endunless
</x-ui.sheet>
