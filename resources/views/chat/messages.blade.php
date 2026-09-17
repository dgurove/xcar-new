{{-- Лента чата (пачка сообщений): разделители дней, «Новые сообщения», склейка подряд идущих
     одного автора, пузыри (chat/message). Приезжает фрагментами «после N», «до N» (more —
     вверху ещё есть) и одним пузырём после правки. readonly — сотрудник читает чужой чат:
     участник слева, вторая сторона справа, имена у всех, меню пузыря нет. --}}
@php
    use App\Chats\AuthorKind;
    $readonly ??= $user !== null && ! $chat->canPost($user);
    $staffEyes = $user?->isStaff() ?? false;
    $readSeq = $chat->readSeqOf($user);
    $firstUnread ??= 0;
    $prev = null;
    $today = now()->startOfDay();
@endphp
@if (!empty($more))<div class="chat-more" data-chat-target="more" data-seq="{{ $messages->first()->seq }}"></div>@endif
@foreach ($messages as $m)
    @php
        $system = $m->author_kind === AuthorKind::System;
        $mine = $readonly ? $m->author_kind === AuthorKind::Staff : $m->isMine($user, $chat);
        $day = $m->created_at->toDateString();
        $cont = $prev && !$system && $prev->author_kind !== AuthorKind::System && $prev->author_id === $m->author_id && $prev->author_kind === $m->author_kind && $prev->created_at->toDateString() === $day && $prev->created_at->diffInMinutes($m->created_at) < 5;
    @endphp
    @if (empty($single) && (!$prev || $prev->created_at->toDateString() !== $day))
        <div class="chat-day" data-day="{{ $day }}">{{ $m->created_at->isToday() ? 'Сегодня' : ($m->created_at->isYesterday() ? 'Вчера' : $m->created_at->translatedFormat($m->created_at->year === $today->year ? 'j F' : 'j F Y')) }}</div>
    @endif
    @if ($firstUnread && $m->seq === $firstUnread)<div class="chat-new" id="chat-new">Новые сообщения</div>@endif
    @include('chat.message', ['m' => $m, 'chat' => $chat, 'user' => $user, 'mine' => $mine, 'system' => $system, 'cont' => $cont, 'readonly' => $readonly, 'staffEyes' => $staffEyes, 'readSeq' => $readSeq, 'day' => $day])
    @php $prev = $m; @endphp
@endforeach
