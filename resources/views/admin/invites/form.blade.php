{{-- Форма пригласительной ссылки админа: менеджеру (одноразовая) или покупателю от имени менеджера.
     Общая для CRM и кабинета на сайте; $action — куда отправлять, $managers — список менеджеров. --}}
<form method="post" action="{{ $action }}" class="invite-form flex flex-col gap-4">
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
