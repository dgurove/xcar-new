{{-- Сделать сотрудника покупателем (14.11.2026, Олег Данилин): отдельная дверь, а не галка ролей. Покупатель — не
     сотрудник: у него один менеджер, своя карточка и доступ к предложениям. Здесь же — кому передать его покупателей,
     если он был менеджером, и что он будет видеть. --}}
@props(['user', 'managers', 'base'])
@php
    $options = $managers->reject(fn ($m) => $m->is($user))->mapWithKeys(fn ($m) => [$m->id => $m->name]);
    $buyers = $user->buyers()->count();
@endphp
<x-ui.sheet id="to-buyer-{{ $user->id }}" :title="'Сделать покупателем: '.$user->shortName()" wide>
    <form method="post" action="{{ $base }}/{{ $user->id }}/buyer" class="flex flex-col gap-3">
        @csrf
        <input type="hidden" name="_user" value="{{ $user->id }}">
        <p class="text-sm text-ink-muted">
            {{ $user->shortName() }} перестанет быть сотрудником: снимутся роли «{{ $user->rolesLabel() }}».
            Покупатель видит предложения своего менеджера и проявляет интерес, но не ведёт CRM, закупки и своих покупателей.
        </p>
        {{-- Живые дела не дадут сохранить (MakeBuyer::blockers): предупреждаем до нажатия, а не после. --}}
        <p class="text-sm text-ink-muted">
            Если у него есть живые подтверждения, сделки или машины в гараже, сначала передайте или закройте их.
        </p>
        <div class="fields">
            <x-ui.field name="manager_id" label="Менеджер" :options="$options" :value="old('manager_id')"
                placeholder="Выберите менеджера" required/>
            @if ($buyers)
                <x-ui.field name="transfer_to" :label="'Передать покупателей ('.$buyers.')'" :options="$options"
                    :value="old('transfer_to')" placeholder="Выберите менеджера" required/>
            @endif
        </div>
        <x-buyer.sees-segment :value="old('buyer_sees', 'all')"/>
        <div class="field">
            <span class="field-label">Доступ к предложениям</span>
            <x-ui.segment name="access" :options="['1' => 'Да, войдёт и увидит', '0' => 'Нет, только для сделок']" :value="old('access', '1')"/>
            <p class="text-sm text-ink-muted">
                {{ $user->hasSignInContact() ? 'Войдёт как раньше — '.$user->loginLabel().'.' : 'Без телефона или почты войти не сможет.' }}
            </p>
        </div>
        @unless ($user->hasSignInContact())
            <x-ui.field name="contact" label="Телефон или почта" placeholder="Чем он будет входить" autocomplete="off"/>
        @endunless
        @error('user')<p class="field-error">{{ $message }}</p>@enderror
        <x-ui.sheet-foot>Сделать покупателем</x-ui.sheet-foot>
    </form>
</x-ui.sheet>
