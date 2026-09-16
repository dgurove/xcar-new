{{-- Приглашения админа в кабинете на сайте: те же ссылки, что в CRM «Пользователи → Ссылки» —
     менеджеру (одноразовая) и покупателю от имени менеджера. «Новая ссылка» — в полосе внизу. --}}
<x-ui.cabinet title="Приглашения" :trail="[['Главная', '/'], ['Кабинет', '/lk'], ['Приглашения']]">
    <div data-controller="sheet" class="contents">
        <x-ui.sheet id="invite-new" title="Пригласительная ссылка" :open="$errors->any()">
            @include('admin.invites.form', ['action' => '/lk/priglasheniya'])
        </x-ui.sheet>
        <x-ui.action-bar>
            <button type="button" class="btn btn-accent min-w-0 flex-1 md:flex-none" data-action="sheet#open"><x-ui.icon name="plus" class="size-5"/> Новая ссылка</button>
        </x-ui.action-bar>
    </div>

    @if ($invites->isEmpty())
        <x-ui.empty>Ссылок пока нет — создайте и отправьте менеджеру или покупателю.</x-ui.empty>
    @else
        @include('admin.invites.list', ['base' => '/lk/priglasheniya'])
    @endif
</x-ui.cabinet>
