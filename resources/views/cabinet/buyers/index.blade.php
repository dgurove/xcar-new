{{-- Покупатели менеджера — вторая пилюля раздела «Сделки» и пункт кабинета (12.10.2026): один экран по двум адресам, как
     чаты. Сверху «Интерес» и «Пригласить», затем группы — свои строки, последняя «Новая группа»; ниже люди. Лупа справа
     от пилюль (при поиске верхних блоков нет: ищут людей). --}}
@if ($base === '/buyers')
<x-ui.shell title="Покупатели" :phone-heading="false" :desktop-heading="false">
<div class="flex flex-col gap-4">
    {{-- Лупа — справа от пилюль раздела; в поиске поле встаёт на их место. На телефоне кнопки — своей строкой под пилюлями:
         в ряду они сжимали пилюли, и те прыгали относительно «Сделок» и «Чатов» (11.10.2026). --}}
    <div class="search-head max-md:flex-wrap">
        <div class="flex min-h-11 min-w-0 flex-1 items-center max-md:basis-full"><x-deal.tabs current="/buyers"/></div>
        <x-ui.toolbar :sort="$term ? null : $sort" search="Имя, логин, телефон" name="buyers" class="max-md:ml-auto"/>
    </div>
    @include('cabinet.buyers.list')
    @include('cabinet.buyers.invite-bar')
</div>
</x-ui.shell>
@else
<x-ui.cabinet title="Покупатели">
<div class="flex flex-col gap-4">
    <x-ui.toolbar :sort="$term ? null : $sort" search="Имя, логин, телефон" name="buyers"/>
    @include('cabinet.buyers.list')
    @include('cabinet.buyers.invite-bar')
</div>
</x-ui.cabinet>
@endif
