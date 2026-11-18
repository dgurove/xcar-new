{{-- Покупатели менеджера — вторая пилюля раздела «Сделки» и пункт кабинета (12.10.2026): один экран по двум адресам, как
     чаты. Сверху «Интерес» и «Пригласить», затем группы — свои строки, последняя «Новая группа»; ниже люди. Лупа справа
     от пилюль (при поиске верхних блоков нет: ищут людей). --}}
@if ($base === '/buyers')
<x-ui.shell title="Покупатели" :heading="false">
<div class="flex flex-col gap-4">
    {{-- Лупа и сортировка — справа от заголовков раздела (`x-deal.heads`); в поиске поле встаёт на их место, на телефоне
         кнопки — строкой ниже у правого края. --}}
    <div class="search-head max-md:flex-wrap">
        <div class="search-lead flex min-h-11 min-w-0 flex-1 items-center max-md:basis-full"><x-deal.heads current="/buyers"/></div>
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
