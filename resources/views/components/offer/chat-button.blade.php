{{-- «Написать» со значком чата — чат площадки с менеджером по машине (19.11.2026, владелец: «Тужиков передумал, хочу Роману
     перед передачей написать, а тут нет кнопочки чата»; «подпиши „Написать“ вместе с иконкой»). Стоит в ряду кнопок шторки
     строки подтверждения, резерва, параллельной и интереса. Переписка есть — ссылка в чат, непрочитанные оранжевым счётчиком;
     нет — та же кнопка заводит чат (`Admin\ChatController::start`). chats — чаты площадки машины по менеджеру
     (`Chat::byManager`), чтобы строки списка не спрашивали каждая своё. --}}
@props(['offer', 'user', 'chats' => null])
@php
    $chat = $chats !== null ? $chats->get($user->id) : \App\Chats\Chat::platformWith($offer, $user)->first();
    $unread = (int) ($chat?->unread_for_staff ?? 0);
    $label = 'Чат с '.$user->shortName();
@endphp
@if ($chat)
    <a href="/work/chats/{{ $chat->id }}" data-turbo-frame="_top" class="btn btn-quiet shrink-0 gap-1.5" aria-label="{{ $label }}"><x-ui.icon name="chat" class="size-5"/>Написать@if ($unread) <span class="badge !bg-urgent">{{ $unread }}</span>@endif</a>
@else
    <form method="post" action="/work/chats/offer/{{ $offer->number }}/{{ $user->id }}" class="contents" data-turbo-frame="_top">@csrf<button type="submit" class="btn btn-quiet shrink-0 gap-1.5" aria-label="{{ $label }}"><x-ui.icon name="chat" class="size-5"/>Написать</button></form>
@endif
