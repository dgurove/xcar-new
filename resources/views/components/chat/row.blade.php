{{-- Строка списка чатов (кабинет и CRM): фото ТС с аватаром собеседника в углу (обращение без
     ТС — только аватар), имя, время, ТС, превью последнего сообщения, бейдж непрочитанного.
     staff — сотрудник смотрит список площадки: имя участника, у переписки покупателя с
     менеджером — с кем, бейдж только у чатов площадки. У участника (менеджер или покупатель пишет площадке
     или своему менеджеру) чат по ТС назван ТС (05.10.2026, владелец): «Администрация XCAR» в каждой строке
     не различало чаты; собеседник — аватаром в углу фото. Личка (22.11.2026, `Chats\Direct`) — аватар собеседника 44 и
     его имя, без строки ТС; чужая личка у админа — участник и чип «→ второй». --}}
@props(['chat', 'me', 'href', 'current' => false, 'staff' => false])
@php
    // Своя переписка пары (личка, свой покупатель) и в списке сотрудника — видом участника: собеседник, его аватар, свой счётчик.
    $staff = $staff && ! ($chat->manager_id && $chat->hasParty($me));
    $other = $staff ? $chat->user : ($chat->isCounterpart($me) ? $chat->user : $chat->manager);
    $mine = $chat->isCounterpart($me);
    $byCar = ! $staff && ! $mine && $chat->offer;
    // Сотруднику чат по ТС тоже назван ТС, человек — второй строкой (22.11.2026, владелец: «у тачек тачки в заголовки, а не имена»).
    $name = $staff ? ($chat->offer ? $chat->offer->titleWithYear() : $chat->displayName()) : ($byCar ? $chat->offer->titleWithYear() : $chat->counterpartName($me));
    $unread = $staff ? ($chat->manager_id ? 0 : $chat->unread_for_staff) : ($chat->isCounterpart($me) ? $chat->unread_for_staff : $chat->unread_for_user);
    $at = $chat->last_message_at;
    // Сотруднику (админ, модератор) — логотип вендора через пробел за названием ТС (24.11.2026, просьба из чатов); менеджерам
    // вендоров не показываем нигде.
    $vendor = $me?->isStaff() && $chat->offer && $name === $chat->offer->titleWithYear() ? $chat->offer->vendor : null;
@endphp
<a href="{{ $href }}" class="chat-row" data-search-row @if ($current) aria-current="true" @endif data-turbo-action="advance">
    @if ($chat->offer)
        <div class="chat-row-photo">
            <x-offer.photo :media="$chat->offer->mainPhoto()" :cat="$chat->offer->category()->icon()" sizes="56px"/>
            @if ($other || !$staff)<x-chat.avatar :user="$other" :size="22" class="chat-row-avatar"/>@endif
        </div>
    @elseif ($other || !$staff)
        <x-chat.avatar :user="$other" :size="44"/>
    @else
        <span class="avatar" style="width:44px;height:44px"><x-ui.icon name="chat" class="size-5"/></span>
    @endif
    <div class="min-w-0 flex-1">
        <div class="flex items-baseline gap-2">
            <span class="{{ $unread ? 'font-medium' : '' }}" data-fill="title">{{ $name }}@if ($vendor) <x-vendor.logo :vendor="$vendor"/>@endif</span>
            @if ($staff && !$chat->user)<span class="tag">гость</span>@endif
            @if ($at)<span class="ml-auto shrink-0 text-xs text-ink-dim nums">{{ \App\Support\Ago::html($at, short: true) }}</span>@endif
        </div>
        @if (! $byCar && ($chat->offer || ($staff && ! $chat->manager_id) || ($mine && ! $chat->isBuyerChat())))<div class="truncate text-sm text-ink-muted">{{ $chat->offer ? ($staff ? $chat->displayName() : $chat->offer->titleWithYear()) : 'Обращение с сайта' }}</div>@endif
        @if ($staff && $chat->manager)<div class="mt-0.5 flex"><x-ui.person :user="$chat->manager" prefix="→"/></div>@endif
        <div class="flex items-center gap-2">
            <span class="truncate text-sm {{ $unread ? '' : 'text-ink-muted' }}">{{ $chat->lastPreview($me) }}</span>
            @if ($unread)<span class="badge ml-auto">{{ $unread }}</span>@endif
        </div>
    </div>
</a>
