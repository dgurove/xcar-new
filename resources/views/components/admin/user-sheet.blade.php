{{-- Шит «Изменить» человека (админ): имя, у покупателя менеджер, у остальных контакты, роли галками и доступ;
     ниже «Войти как», ссылка на новый пароль, закрыть доступ или удалить. Открывается сам, если ссылка на пароль только что выдана.
     07.10.2026: поля строками (.fields), кнопка .sheet-foot. --}}
@props(['user', 'managers', 'link' => null, 'base', 'me'])
@php
    use App\Http\Admin\UserController;
@endphp
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
            {{-- Логин и контакты — строкой текста (10.10.2026: «что-то чипами, что-то текстом»). --}}
            <div class="detail-line !mt-0 text-sm text-ink-muted">
                @if ($user->login)<span class="nums">{{ $user->login }}</span>@endif
                @if ($user->phone)<span class="nums whitespace-nowrap">{{ $user->phoneFormatted() }}</span>@endif
                @if ($user->email)<span>{{ $user->email }}</span>@endif
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
            <div data-managers class="flex flex-col gap-3">
                <x-admin.group-picker :groups="\App\Users\UserGroup::ofKind(\App\Users\UserGroup::MANAGERS)->get()" :user="$user" name="manager"/>
                {{-- Партнёр по логистике (10.10.2026): в этих регионах везёт сам за стоимость эвакуации и видит свои перевозки.
                     Только у менеджера: в списке «Пользователей» шторка стоит у каждой строки, 89 регионов у каждой — лишнее. --}}
                @php $partnerIn = array_map('intval', old('partner_regions', $user->partner_regions ?? [])); @endphp
                @if ($user->isManager())
                <div class="field">
                    <label for="partner-regions-{{ $user->id }}" class="field-label">Партнёр по логистике</label>
                    <select id="partner-regions-{{ $user->id }}" name="partner_regions[]" multiple size="5" class="field-input">
                        @foreach (\App\Logistics\Choices::regions() as $id => $name)<option value="{{ $id }}" @selected(in_array($id, $partnerIn, true))>{{ $name }}</option>@endforeach
                    </select>
                    @error('partner_regions')<p class="field-error">{{ $message }}</p>@enderror
                </div>
                @endif
            </div>
            <div data-crm class="flex flex-col gap-3">
                <x-admin.crm-access-fields :areas="$user->access ?? []" :user="$user"/>
            </div>
            <x-ui.check name="mail" :checked="$user->wantsMail()">Письма о событиях</x-ui.check>
        @endif
        <x-ui.sheet-foot>Сохранить</x-ui.sheet-foot>
    </form>
    @if (\App\Users\Impersonation::allowed($me, $user) && $user->isApproved())
        <form method="post" action="{{ $base }}/{{ $user->id }}/impersonate" class="mt-3">
            @csrf
            <x-ui.button type="submit" variant="secondary" block><x-ui.icon name="login" class="size-5"/> Войти как {{ $user->shortName() }}</x-ui.button>
        </form>
    @endif
    @unless ($user->is($me))
        @if (\App\Users\Access\Lock::enabled() && $user->underLock() && $user->telegram_chat_id)
            {{-- Под замком вход только через Telegram: потерял его — отвязать, дальше вход паролем и привязка нового. --}}
            <form method="post" action="{{ $base }}/{{ $user->id }}/telegram/unlink" class="mt-3"
                data-turbo-confirm="Отвязать Telegram {{ $user->shortName() }}?" data-turbo-confirm-label="Отвязать"
                data-turbo-confirm-text="Выйдет с устройства, войдёт паролем и привяжет новый Telegram">
                @csrf
                <x-ui.button type="submit" variant="secondary" block><x-telegram.logo plain class="size-5"/> Отвязать Telegram</x-ui.button>
            </form>
        @else
        <form method="post" action="{{ $base }}/{{ $user->id }}/password" class="mt-3"
            data-turbo-confirm="Выдать ссылку для нового пароля?" data-turbo-confirm-label="Выдать"
            data-turbo-confirm-text="{{ $user->name }} откроет её и придумает пароль сам. Прежние ссылки погаснут, текущий пароль пока действует.">
            @csrf
            <x-ui.button type="submit" variant="secondary" block><x-ui.icon name="link" class="size-5"/> Ссылка для нового пароля</x-ui.button>
        </form>
        @endif
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
