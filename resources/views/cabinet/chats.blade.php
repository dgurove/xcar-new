{{-- Чаты как в мессенджере: панель строк (собеседник, ТС, последнее сообщение, бейдж); на широком
     экране справа открытый чат (фрейм chat-screen, split_controller ведёт строки в него), на
     телефоне — либо список, либо экран (current). current = 'new' — экран «написать» по ТС без чата. --}}
@php
    $me = auth()->user();
    $current ??= null;
    $base ??= \App\Chats\Chat::ROOT;
    $chat ??= null;
    $offer ??= null;
    $other = $chat ? ($chat->isCounterpart($me) ? $chat->user : $chat->manager) : (($offer || $current === 'support') && $me->isBuyer() ? $me->manager : null);
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
    // «Администрация XCAR» в списке есть всегда: обращения ещё нет — строка ведёт на пустой экран. Покупателю менеджера —
    // вместо неё его менеджер (12.10.2026, владелец: «покупатель пишет менеджеру, площадку убрать»).
    $q ??= '';
    $supportTo = $me->isBuyer() ? $me->manager : null;
    // При поиске строку без обращения не рисуем: она не совпала ни с чем.
    $support = !$me->isAdmin() && $q === '' && $chats->doesntContain(fn ($c) => $c->isEnquiry() && $c->manager_id === $supportTo?->id);
@endphp
{{-- Чаты — раздел таб-бара (18.11.2026, владелец: «по центру в нижнем тулбаре разместил бы чаты — пользователи очень давно
     просили»; до того экран жил и в кабинете `/account/chats`, и пилюлей «Сделок» `/deals/chats`). Заголовок «Чаты» — у
     списка (`cabinet.chats-split`), у открытого чата на телефоне — своя шапка с «назад» к списку. --}}
<x-ui.shell :title="$current ? $name : 'Чаты'" :back="$current ? ['Чаты', $base] : false" :heading="false" :back-row="false">
    {{-- Заголовок раздела, как у «Чатов» CRM; открыт чат на телефоне — прячется (`body.chat-open`). --}}
    <div class="chat-crm-top">
        <h1 class="head-title text-xl sm:text-2xl">Чаты</h1>
    </div>
    @include('cabinet.chats-split')
</x-ui.shell>
