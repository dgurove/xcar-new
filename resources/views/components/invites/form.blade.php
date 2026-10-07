{{-- Форма новой ссылки. Админ: менеджеру, сотруднику или управляющему парковкой (одноразовая, со сроком; управляющему —
     сразу с доступом: что открыто сверх заявок и наличия, какая парковка, только приёмка; модератору — почта сверх
     черновиков) или покупателю от имени
     менеджера; менеджер: покупателю, сразу в группу. Что покупатель укажет о себе — по умолчанию ничего.
     07.10.2026: поля строками (.fields), кнопка .sheet-foot. --}}
@props(['action', 'admin' => false, 'managers' => collect(), 'groups' => collect()])
<form method="post" action="{{ $action }}" class="invite-form flex flex-col gap-3">
    @csrf
    @if ($admin)
        {{-- Четыре роли в сегменты не помещаются на телефоне — селект. --}}
        <div class="fields"><x-ui.field name="role" label="Кому" :options="['manager' => 'Менеджеру', 'moderator' => 'Модератору', 'admin' => 'Администратору', 'parking' => 'Управляющему парковкой', 'buyer' => 'Покупателю']" :value="old('role', 'manager')"/></div>
        <div data-park class="flex flex-col gap-3">
            <x-park.access-fields/>
        </div>
        <div data-managers class="fields">
            <x-admin.group-picker :groups="\App\Users\UserGroup::ofKind(\App\Users\UserGroup::MANAGERS)->get()" name="manager"/>
        </div>
        <div data-crm class="flex flex-col gap-3">
            <x-admin.crm-access-fields/>
        </div>
    @endif
    @if ($admin)
        {{-- Всем, кроме покупателя, — одноразовая и со сроком: названия у неё нет, только до какого момента действует. --}}
        <div data-once class="fields">
            <x-ui.field name="expires_at" label="Действует до" type="datetime-local" :value="now()->addDays(3)->format('Y-m-d\TH:i')" :min="now()->format('Y-m-d\TH:i')"/>
        </div>
    @endif
    <div @if ($admin) data-buyer @endif class="flex flex-col gap-3">
        <div class="fields">
            <x-ui.field name="label" label="Название" maxlength="60" placeholder="Кому: имя, компания, выставка…"/>
            @if ($admin)
                <x-ui.field name="manager_id" label="Менеджер" :options="$managers->mapWithKeys(fn ($m) => [$m->id => $m->name])" placeholder="Выберите менеджера"/>
            @elseif ($groups->isNotEmpty())
                <x-ui.field name="group_id" label="В группу" :options="$groups->pluck('name', 'id')" placeholder="Без группы"/>
            @endif
        </div>
        <div class="flex flex-col gap-2">
            {{-- Телефон по умолчанию спрашивается (владелец 05.10.2026); снять — если менеджер не хочет отдавать номер покупателя. --}}
            <x-ui.check name="phone" :checked="old('_token') ? (bool) old('phone') : true">Покупатель указывает телефон</x-ui.check>
            <x-ui.check name="email">Покупатель указывает почту</x-ui.check>
        </div>
    </div>
    <div class="sheet-foot"><x-ui.button block>Создать ссылку</x-ui.button></div>
</form>
