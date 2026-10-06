{{-- Шапка колонки чатов: поле поиска над строками, открытое во всю ширину колонки (06.10.2026, владелец: «нет смысла
     сворачивать») — в CRM, у бота, у менеджера и покупателя.
     Живой поиск подменяет только строки (#chat-rows-body), поле остаётся. Строк пять и меньше — искать нечего, лупы нет;
     поиск уже идёт (q в адресе) — она есть. --}}
@props(['search', 'url', 'count', 'q' => ''])
@if ($count > 5 || $q !== '')
    <div class="chat-list-head"><x-ui.toolbar :search="$search" search-target="#chat-rows-body" :search-url="$url" name="chats" search-open/></div>
@endif
