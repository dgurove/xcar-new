{{-- Лента переписки бота пачкой: дни, склейка подряд идущих одной стороны, пузыри (admin.telegram.message).
     Протокол chat_controller: data-seq — id строки журнала; «до N» с more — вверху есть старше. --}}
@php
    $prev = null;
    $today = now()->startOfDay();
@endphp
@if (!empty($more))<div class="chat-more" data-chat-target="more" data-seq="{{ $messages->first()->id }}"></div>@endif
@foreach ($messages as $m)
    @php
        $day = $m->created_at->toDateString();
        $who = $m->direction.':'.($m->author_id ?? 0);
        $cont = $prev && !$m->isEvent() && !$prev->isEvent() && $prev->direction.':'.($prev->author_id ?? 0) === $who && $prev->created_at->toDateString() === $day && $prev->created_at->diffInMinutes($m->created_at) < 5;
    @endphp
    @if (empty($single) && (!$prev || $prev->created_at->toDateString() !== $day))
        <div class="chat-day" data-day="{{ $day }}">{{ $m->created_at->isToday() ? 'Сегодня' : ($m->created_at->isYesterday() ? 'Вчера' : $m->created_at->translatedFormat($m->created_at->year === $today->year ? 'j F' : 'j F Y')) }}</div>
    @endif
    @include('admin.telegram.message', ['m' => $m, 'chat' => $chat, 'cont' => $cont, 'day' => $day, 'who' => $who])
    @php $prev = $m; @endphp
@endforeach
