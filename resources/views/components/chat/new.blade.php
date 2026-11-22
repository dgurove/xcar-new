{{-- «Написать» над списком чатов (22.11.2026, менеджеры: «очень не хватает кнопки написать, чтобы начать диалог, если его
     нет»): шторка выбора человека из тех, кому можно (`Chats\Direct::partners`), группами и с поиском. Выбор уходит формой GET
     на `/with?user=` — там личка есть: на её экран, нет — пустой экран, первое сообщение её заведёт. --}}
@php $partners = \App\Chats\Direct::partners(auth()->user()); @endphp
@if ($partners->isNotEmpty())
    <form method="get" action="{{ \App\Chats\Chat::base() }}/with" class="contents" data-turbo-action="advance">
        <x-people.pick name="user" kind="chat" variant="pill" placeholder="Написать" title="Написать" :people="$partners" search autosubmit :marks="false"/>
    </form>
@endif
