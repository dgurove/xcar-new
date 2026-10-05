{{-- Шапка колонки чатов: лупа над строками, как у бота (05.10.2026, владелец) — в CRM, у бота, у менеджера и покупателя.
     Живой поиск подменяет только строки (#chat-rows-body), поле остаётся. Строк пять и меньше — искать нечего, лупы нет;
     поиск уже идёт (q в адресе) — она есть. --}}
@props(['search', 'url', 'count', 'q' => ''])
@if ($count > 5 || $q !== '')
    <div class="chat-list-head"><x-ui.toolbar :search="$search" search-target="#chat-rows-body" :search-url="$url" name="chats"/></div>
@endif
