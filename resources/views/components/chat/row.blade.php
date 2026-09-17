{{-- Строка списка чатов (кабинет и CRM): фото ТС с аватаром собеседника в углу (обращение без
     ТС — только аватар), имя, время, ТС, превью последнего сообщения, бейдж непрочитанного.
     staff — сотрудник смотрит список площадки: имя участника, у переписки покупателя с
     менеджером — с кем, бейдж только у чатов площадки. --}}
@props(['chat', 'me', 'href', 'current' => false, 'staff' => false])
@php
    $other = $staff ? $chat->user : ($chat->isCounterpart($me) ? $chat->user : $chat->manager);
    $name = $staff ? $chat->displayName() : $chat->counterpartName($me);
    $unread = $staff ? ($chat->manager_id ? 0 : $chat->unread_for_staff) : ($chat->isCounterpart($me) ? $chat->unread_for_staff : $chat->unread_for_user);
    $at = $chat->last_message_at;
@endphp
<a href="{{ $href }}" class="chat-row" @if ($current) aria-current="true" @endif data-turbo-action="advance">
    @if ($chat->offer)
        <div class="chat-row-photo">
            <x-offer.photo :media="$chat->offer->mainPhoto()" sizes="56px"/>
            @if ($other)<x-ui.avatar :user="$other" :size="22" class="chat-row-avatar"/>@endif
        </div>
    @elseif ($other)
        <x-ui.avatar :user="$other" :size="44"/>
    @else
        <span class="avatar" style="width:44px;height:44px"><x-ui.icon name="chat" class="size-5"/></span>
    @endif
    <div class="min-w-0 flex-1">
        <div class="flex items-baseline gap-2">
            <span class="truncate {{ $unread ? 'font-medium' : '' }}">{{ $name }}</span>
            @if ($staff && !$chat->user)<span class="tag">гость</span>@endif
            @if ($at)<span class="ml-auto shrink-0 text-xs text-ink-dim nums">{{ $at->translatedFormat($at->isToday() ? 'H:i' : ($at->year === now()->year ? 'j M' : 'd.m.y')) }}</span>@endif
        </div>
        @if ($staff && $chat->manager)<div class="mt-0.5 flex"><x-ui.person :user="$chat->manager" prefix="→"/></div>@endif
        <div class="truncate text-[13px] text-ink-muted">{{ $chat->offer ? $chat->offer->titleWithYear() : 'Обращение с сайта' }}</div>
        <div class="flex items-center gap-2">
            <span class="truncate text-sm {{ $unread ? '' : 'text-ink-muted' }}">{{ $chat->lastPreview($me) }}</span>
            @if ($unread)<span class="badge ml-auto">{{ $unread }}</span>@endif
        </div>
    </div>
</a>
