{{-- Сделать покупателя менеджером (14.11.2026): обратная дверь к «Сделать покупателем…». Покупатель — не галка
     ролей: у него менеджер, `buyer_sees`, группы покупателей и показы. Здесь они снимаются, а роли ставятся менеджерские.
     Гараж и живая сделка покупателя не пускают: иначе он увидит закупочную или выпадет из шага сделки. --}}
@props(['user', 'base', 'blockers' => null])
@php
    $blockers ??= \App\Users\Actions\MakeManager::blockers($user);
    $manager = $user->manager;
@endphp
<x-ui.sheet id="to-manager-{{ $user->id }}" :title="'Сделать менеджером: '.$user->shortName()" wide>
    <form method="post" action="{{ $base }}/{{ $user->id }}/manager" class="flex flex-col gap-3">
        @csrf
        <input type="hidden" name="_user" value="{{ $user->id }}">
        <p class="text-sm text-ink-muted">
            {{ $user->shortName() }} перестанет быть покупателем{{ $manager ? ' '.$manager->shortName() : '' }}:
            снимутся группы покупателей и показы. Откроются цены, подтверждения, закупки, сделки и свои покупатели.
        </p>
        @if ($blockers)
            <div class="flash flash-urgent text-sm">
                Сейчас перевести нельзя: {{ implode(', ', $blockers) }}.
                Сначала закройте или передайте их.
            </div>
            {{-- Живые дела и гараж не дадут сохранить (MakeManager::blockers): говорим до нажатия, а не после. --}}
        @endif
        @unless ($user->hasSignInContact())
            <x-ui.field name="contact" label="Телефон или почта" placeholder="Чем он будет входить" autocomplete="off" required/>
        @endunless
        @if ($user->password)
            <p class="text-sm text-ink-muted">Войдёт как раньше — {{ $user->loginLabel() }}.</p>
        @else
            <input type="hidden" name="password" value="1">
            <p class="text-sm text-ink-muted">Пароля у него ещё нет: после перевода выдадим ссылку на новый и покажем здесь же.</p>
        @endif
        @error('user')<p class="field-error">{{ $message }}</p>@enderror
        <x-ui.sheet-foot :disabled="(bool) $blockers">Сделать менеджером</x-ui.sheet-foot>
    </form>
</x-ui.sheet>
