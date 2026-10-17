{{-- Форма новой ссылки. Админ: менеджеру, сотруднику или супер-покупателю (одноразовая, со сроком; модератору — почта
     сверх черновиков) или покупателю от имени
     менеджера; менеджер: покупателю, сразу в группу. Что покупатель укажет о себе — по умолчанию ничего.
     07.10.2026: поля строками (.fields), кнопка .sheet-foot.
     18.10.2026 (владелец: «создавать покупателя самому, а не через ссылку»): сегмент «Ввести данные | Ссылкой» — с данными
     человек заводится сразу (`AddPerson`), роль, группы и «что видит» те же; только для ссылки — срок, название и что
     указывает покупатель (`data-link`), только для данных — имя и телефон (`data-person`); видимость — CSS по сегменту. --}}
@props(['action', 'admin' => false, 'managers' => collect(), 'groups' => collect()])
<form method="post" action="{{ $action }}" class="invite-form flex flex-col gap-3">
    @csrf
    <x-ui.segment name="mode" :options="['person' => 'Ввести данные', 'link' => 'Ссылкой']" :value="old('mode', 'person')"/>
    <div data-person class="fields">
        <x-ui.field name="first_name" label="Имя" required autocomplete="off"/>
        <x-ui.field name="last_name" label="Фамилия" autocomplete="off"/>
        <x-ui.field name="person_phone" label="Телефон" type="tel" required/>
        <x-ui.field name="person_email" label="Почта" type="email"/>
    </div>
    @if ($admin)
        {{-- Пять ролей в сегменты не помещаются на телефоне — селект. Супер-покупателю — одноразовая, как сотруднику. --}}
        <div class="fields"><x-ui.field name="role" label="Кому" :options="['manager' => 'Менеджеру', 'moderator' => 'Модератору', 'admin' => 'Администратору', 'buyer' => 'Покупателю', 'super_buyer' => 'Супер-покупателю', 'logist' => 'Логисту', 'driver' => 'Водителю']" :value="old('role', 'manager')"/></div>
        <div data-managers class="fields">
            <x-admin.group-picker :groups="\App\Users\UserGroup::ofKind(\App\Users\UserGroup::MANAGERS)->get()" name="manager"/>
        </div>
        <div data-crm class="flex flex-col gap-3">
            <x-admin.crm-access-fields/>
        </div>
    @endif
    @if ($admin)
        {{-- Всем, кроме покупателя, — одноразовая и со сроком: названия у неё нет, только до какого момента действует. --}}
        <div data-once data-link class="fields">
            <x-ui.field name="expires_at" label="Действует до" type="datetime-local" :value="now()->addDays(3)->format('Y-m-d\TH:i')" :min="now()->format('Y-m-d\TH:i')"/>
        </div>
    @endif
    <div @if ($admin) data-buyer @endif class="flex flex-col gap-3">
        <div data-link class="fields"><x-ui.field name="label" label="Название" maxlength="60" placeholder="Кому: имя, компания, выставка…"/></div>
        <div class="fields empty:hidden">@if ($admin)
                <x-ui.field name="manager_id" label="Менеджер" :options="$managers->mapWithKeys(fn ($m) => [$m->id => $m->name])" placeholder="Выберите менеджера"/>
            @elseif ($groups->isNotEmpty())
                <x-ui.field name="group_id" label="В группу" :options="$groups->pluck('name', 'id')" placeholder="Без группы"/>
            @endif</div>
        {{-- Что увидит покупатель (12.10.2026, владелец: «неразбериха с покупателями»): по умолчанию всё, что открыто менеджеру. --}}
        <x-buyer.sees-segment/>
        <div data-link class="flex flex-col gap-2">
            {{-- Телефон по умолчанию спрашивается (владелец 05.10.2026); снять — если менеджер не хочет отдавать номер покупателя. --}}
            <x-ui.check name="phone" :checked="old('_token') ? (bool) old('phone') : true">Покупатель указывает телефон</x-ui.check>
            <x-ui.check name="email">Покупатель указывает почту</x-ui.check>
        </div>
    </div>
    <x-ui.sheet-foot><span data-person>Добавить</span><span data-link>Создать ссылку</span></x-ui.sheet-foot>
</form>
