{{-- Форма новой ссылки. Админ: Менеджеру (одноразовая) или Покупателю от имени менеджера;
     менеджер: покупателю, сразу в группу. Что покупатель укажет о себе — по умолчанию ничего. --}}
@props(['action', 'admin' => false, 'managers' => collect(), 'groups' => collect()])
<form method="post" action="{{ $action }}" class="invite-form flex flex-col gap-4">
    @csrf
    @if ($admin)
        <div class="flex rounded-(--radius-m) bg-surface-3 p-1">
            @foreach (['manager' => 'Менеджеру', 'buyer' => 'Покупателю'] as $opt => $text)
                <label class="tri flex-1"><input type="radio" name="role" value="{{ $opt }}" class="sr-only" @checked(old('role', 'manager') === $opt)><span class="block rounded-(--radius-s) py-2 text-center text-sm text-ink-muted">{{ $text }}</span></label>
            @endforeach
        </div>
    @endif
    @if ($admin)
        {{-- Менеджерская — одноразовая и со сроком: названия у неё нет, только до какого момента действует. --}}
        <div data-manager class="flex flex-col gap-3">
            <x-ui.field name="expires_at" label="Действует до" type="datetime-local" :value="now()->addDays(3)->format('Y-m-d\TH:i')" :min="now()->format('Y-m-d\TH:i')"/>
            <p class="text-sm text-ink-muted">Ссылка одноразовая: после регистрации менеджера перестанет действовать.</p>
        </div>
    @endif
    <div @if ($admin) data-buyer @endif class="flex flex-col gap-4">
        <x-ui.field name="label" label="Название" maxlength="60" placeholder="Кому: имя, компания, выставка…"/>
        @if ($admin)
            <x-ui.field name="manager_id" label="Менеджер" :options="$managers->mapWithKeys(fn ($m) => [$m->id => $m->name])" placeholder="Выберите менеджера"/>
        @elseif ($groups->isNotEmpty())
            <x-ui.field name="group_id" label="Сразу в группу" :options="$groups->pluck('name', 'id')" placeholder="Без группы"/>
        @endif
        <div class="flex flex-col gap-2">
            <x-ui.check name="phone">Покупатель указывает телефон</x-ui.check>
            <x-ui.check name="email">Покупатель указывает почту</x-ui.check>
        </div>
    </div>
    <x-ui.button block>Создать ссылку</x-ui.button>
</form>
