{{-- Заголовок экрана «Чатов» — один на CRM, сайт и XCAR Chat; открыт чат на телефоне — прячется (`body.chat-open`). В XCAR
     Chat шапки нет: профиль, тема и выход — аватаром справа (`x-ui.me-menu`). Ряд — внутри: у `.chat-crm-top` свой
     `display: none` в открытом чате, утилита `flex` на нём его перебила бы. --}}
<div class="chat-crm-top">
    <div class="flex items-center justify-between gap-3">
        <h1 class="head-title text-xl sm:text-2xl">Чаты</h1>
        @if (\App\Support\Surface::current() === \App\Support\Surface::Chat)
            <x-ui.me-menu/>
        @endif
    </div>
</div>
