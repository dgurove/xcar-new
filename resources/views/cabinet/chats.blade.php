{{-- Чаты как в мессенджере: панель строк (собеседник, ТС, последнее сообщение, бейдж); на широком
     экране справа открытый чат (фрейм chat-screen, split_controller ведёт строки в него), на
     телефоне — либо список, либо экран (current). current = 'new' — экран «написать» по ТС без чата. --}}
@php
    $me = auth()->user();
    $current ??= null;
    $base ??= '/account/chats';
    $chat ??= null;
    $offer ??= null;
    $other = $chat ? ($chat->isCounterpart($me) ? $chat->user : $chat->manager) : ($offer && $me->isBuyer() ? $me->manager : null);
    $name = $chat ? $chat->counterpartName($me) : ($other?->shortName() ?? \App\Chats\Chat::PLATFORM);
    // Шапка ведёт к собеседнику: менеджеру — страница покупателя с возвратом в чат; покупателю и всем,
    // кто пишет площадке, — шторка-контакт; статус там по рабочему времени, а не по seen_at.
    $mine = $chat && $chat->isCounterpart($me);
    // Покупатель мог уйти к другому менеджеру — чат остался, а его страницы у меня больше нет;
    // сотруднику из чата площадки — карточка человека на этом же хосте.
    $link = match (true) {
        ! $mine || ! $other => null,
        $me->isAdmin() => '/account/users/'.$other->id.'?chat='.$chat->id,
        $other->manager_id === $me->id => '/buyers/'.$other->id.'?chat='.$chat->id,
        default => null,
    };
    $status = $mine ? false : \App\Chats\Hours::presence(feminine: $other === null);
    $others = $mine || ! $other ? collect() : $chats->filter(fn ($c) => $c->manager_id === $other->id && $c->id !== $chat?->id);
    // «Администрация XCar» в списке есть всегда: обращения ещё нет — строка ведёт на пустой экран.
    $q ??= '';
    // При поиске строку «Администрации» без обращения не рисуем: она не совпала ни с чем.
    $support = !$me->isAdmin() && $q === '' && $chats->doesntContain(fn ($c) => $c->isEnquiry());
@endphp
{{-- Те же чаты — и в «Сделках» (`/deals/chats`, пилюля рядом со «Сделками» и «Покупателями»; 11.10.2026, владелец: «в
     кабинете чаты обычно не ищут — пусть будут и там и там»), и в кабинете (`/account/chats`). --}}
@if (str_starts_with($base, '/deals'))
<x-ui.shell :title="$current ? $name : 'Чаты'" :back="$current ? ['Чаты', $base] : false" :phone-heading="false" :desktop-heading="false">
    <div @class(['mb-4', 'max-md:hidden' => $current])><x-deal.tabs current="/deals/chats"/></div>
    @include('cabinet.chats-split')
</x-ui.shell>
@else
<x-ui.cabinet :title="$current ? $name : 'Чаты'">
    @include('cabinet.chats-split')
</x-ui.cabinet>
@endif
