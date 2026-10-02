{{-- Шит «Изменить» человека (админ): имя, у покупателя менеджер, у остальных контакты, роль и доступ;
     ниже «Войти как», ссылка на новый пароль, закрыть доступ или удалить. Открывается сам, если ссылка только что выдана. --}}
@props(['user', 'managers', 'link' => null, 'base', 'me'])
@php
    use App\Http\Admin\UserController;
    $as = (session('impersonation_link')['user'] ?? null) === $user->id ? session('impersonation_link') : null;
@endphp
{{-- Ссылка «Войти как» — своей шторкой: после выдачи Turbo морфит страницу, новая шторка подключается и открывается сама. --}}
@if ($as)
    <div data-controller="sheet" class="contents">
        <x-ui.sheet :id="'as-'.$user->id" :title="'Вход как '.$user->shortName()" open>
            <x-ui.copy-link :url="$as['url']" :title="'Вход как '.$user->shortName()"/>
        </x-ui.sheet>
    </div>
@endif
<x-ui.sheet id="user-{{ $user->id }}" :title="$user->name" :open="($link['user'] ?? null) === $user->id">
    @if (($link['user'] ?? null) === $user->id)
        <x-ui.copy-link :url="$link['url']" title="Ссылка для нового пароля" class="mb-6">
            <p class="text-sm text-ink-muted">Действует сутки, один раз. Отдайте её {{ $user->shortName() }} любым способом.</p>
        </x-ui.copy-link>
    @endif
    <form method="post" action="{{ $base }}/{{ $user->id }}" class="user-form flex flex-col gap-4">
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
            {{-- Парковка — не галка у любой роли, а роль «Парковка» со своим доступом; админу открыто всё. --}}
            <div data-park class="flex flex-col gap-3">
                <x-park.access-fields :areas="$user->isParking() ? ($user->access ?? []) : []" :yard="$user->park_yard_id" :readonly="$user->park_readonly"/>
            </div>
            <div data-managers class="flex flex-col gap-3">
                <x-admin.group-picker :groups="\App\Users\UserGroup::ofKind(\App\Users\UserGroup::MANAGERS)->get()" :user="$user" name="manager"/>
            </div>
            <div data-crm class="flex flex-col gap-3">
                <x-admin.crm-access-fields :areas="$user->isModerator() ? ($user->access ?? []) : []" :user="$user"/>
            </div>
            <x-ui.check name="mail" :checked="$user->wantsMail()">Письма о событиях</x-ui.check>
        @endif
        <x-ui.button block>Сохранить</x-ui.button>
    </form>
    @if (\App\Users\Impersonation::allowed($me, $user) && $user->isApproved())
        <form method="post" action="{{ $base }}/{{ $user->id }}/impersonate" class="mt-3"
            data-turbo-confirm="Войти как {{ $user->shortName() }}?" data-turbo-confirm-label="Получить ссылку"
            data-turbo-confirm-text="Одноразовая ссылка на {{ \App\Users\Impersonation::MINUTES }} минут. Откройте её в окне инкогнито, иначе в этом окне вы выйдете">
            @csrf
            <x-ui.button type="submit" variant="secondary" block><x-ui.icon name="login" class="size-5"/> Войти как {{ $user->shortName() }}</x-ui.button>
        </form>
    @endif
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
