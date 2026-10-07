{{-- Шит «Изменить» человека (админ): имя, у покупателя менеджер, у остальных контакты, роли галками и доступ;
     ниже «Войти как», ссылка на новый пароль, закрыть доступ или удалить. Открывается сам, если ссылка только что выдана.
     07.10.2026: поля строками (.fields), кнопка .sheet-foot. --}}
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
{{-- Не прошло проверку — шторка этого человека открывается снова с ошибками (old('_user')). --}}
<x-ui.sheet id="user-{{ $user->id }}" :title="$user->name" tall :open="($link['user'] ?? null) === $user->id || (int) old('_user') === $user->id">
    @if (($link['user'] ?? null) === $user->id)
        <x-ui.copy-link :url="$link['url']" title="Ссылка для нового пароля" class="mb-6">
            <p class="text-sm text-ink-muted">Действует сутки, один раз. Отдайте её {{ $user->shortName() }} любым способом.</p>
        </x-ui.copy-link>
    @endif
    <form method="post" action="{{ $base }}/{{ $user->id }}" class="user-form flex flex-col gap-3">
        @csrf @method('put')
        <input type="hidden" name="_user" value="{{ $user->id }}">
        <div class="fields">
            <x-ui.field name="first_name" id="user-first-{{ $user->id }}" label="Имя" :value="$user->first_name" required/>
            <x-ui.field name="last_name" id="user-last-{{ $user->id }}" label="Фамилия" :value="$user->last_name" required/>
        </div>
        @if ($user->isBuyer())
            <div class="flex flex-wrap gap-1.5">
                @if ($user->login)<span class="tag nums">{{ $user->login }}</span>@endif
                @if ($user->phone)<span class="tag nums">{{ $user->phoneFormatted() }}</span>@endif
                @if ($user->email)<span class="tag">{{ $user->email }}</span>@endif
            </div>
            <div class="fields">
                <x-ui.field name="manager_id" label="Менеджер" :options="$managers->mapWithKeys(fn ($m) => [$m->id => $m->name])" :value="$user->manager_id"/>
            </div>
        @else
            <div class="fields">
                <x-ui.field name="phone" label="Телефон" type="tel" :value="$user->phoneFormatted()"/>
                <x-ui.field name="email" label="Почта" type="email" :value="$user->email"/>
                <x-ui.field name="login" label="Логин" :value="$user->login" autocapitalize="none"/>
            </div>
            {{-- Роли галками: их может быть несколько (менеджер и модератор, админ и менеджер…). Под каждой отмеченной — её
                 настройки. Себе «Админ» не снять. --}}
            @php $checked = old('roles', ($user->roles ?? collect())->map->value->all()); @endphp
            <div class="field">
                <span class="field-label">Роли</span>
                <div class="flex flex-wrap gap-x-5 gap-y-2.5">
                    @foreach (UserController::ROLES as $r)
                        <label class="check">
                            <input type="checkbox" switch name="roles[]" value="{{ $r->value }}" @checked(in_array($r->value, $checked, true)) @if ($r === \App\Users\Role::Admin && $user->is($me)) disabled @endif>
                            <span>{{ $r->label() }}</span>
                        </label>
                    @endforeach
                </div>
                @error('roles')<p class="field-error">{{ $message }}</p>@enderror
            </div>
            {{-- Сняли «Менеджер» у того, у кого есть покупатели, — к кому они перейдут. --}}
            @if ($user->isManager() && ($buyers = $user->buyers()->count()))
                <div data-transfer class="fields">
                    <x-ui.field name="transfer_to" :label="'Передать покупателей ('.$buyers.')'" :options="$managers->reject(fn ($m) => $m->is($user))->mapWithKeys(fn ($m) => [$m->id => $m->name])" placeholder="Выберите менеджера"/>
                </div>
            @endif
            {{-- Парковка — не галка у любой роли, а роль «Парковка» со своим доступом; админу открыто всё. --}}
            <div data-park class="flex flex-col gap-3">
                <x-park.access-fields :areas="$user->access ?? []" :yard="$user->park_yard_id" :readonly="$user->park_readonly"/>
            </div>
            <div data-managers class="flex flex-col gap-3">
                <x-admin.group-picker :groups="\App\Users\UserGroup::ofKind(\App\Users\UserGroup::MANAGERS)->get()" :user="$user" name="manager"/>
            </div>
            <div data-crm class="flex flex-col gap-3">
                <x-admin.crm-access-fields :areas="$user->access ?? []" :user="$user"/>
            </div>
            <x-ui.check name="mail" :checked="$user->wantsMail()">Письма о событиях</x-ui.check>
        @endif
        <div class="sheet-foot"><x-ui.button block>Сохранить</x-ui.button></div>
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
