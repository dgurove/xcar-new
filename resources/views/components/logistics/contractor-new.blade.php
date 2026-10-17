{{-- «+ Исполнитель» логиста (18.10.2026): строка `.list` и шторка с данными — исполнитель заводится сразу (`AddPerson`),
     пароль — шторкой входа. Один кусок на «Исполнителях» и в форме заявки (`back=order` — вернуться в форму). --}}
@props(['back' => null, 'label' => '+ Исполнитель'])
<div class="contents" data-controller="sheet">
    <button type="button" class="row w-full text-left transition-colors hover:bg-hover" data-action="sheet#open">
        <x-ui.row-icon name="plus" tone="accent"/>
        <span class="min-w-0 flex-1 font-medium">{{ ltrim($label, '+ ') }}</span>
    </button>
    <x-ui.sheet id="contractor-new" title="Исполнитель">
        <form method="post" action="/contractors" class="flex flex-col gap-4">
            @csrf
            @if ($back)<input type="hidden" name="back" value="{{ $back }}">@endif
            <div class="fields">
                <x-ui.field name="first_name" id="contractor-new-first" label="Имя" required autocomplete="off"/>
                <x-ui.field name="last_name" id="contractor-new-last" label="Фамилия" autocomplete="off"/>
                <x-ui.field name="person_phone" id="contractor-new-phone" label="Телефон" type="tel" required/>
                <x-ui.field name="logistics_city" id="contractor-new-city" label="Город"/>
                <x-ui.field name="cdek_point" id="contractor-new-cdek" label="Пункт СДЭК" type="textarea"/>
            </div>
            <x-ui.sheet-foot>Добавить</x-ui.sheet-foot>
        </form>
    </x-ui.sheet>
</div>
