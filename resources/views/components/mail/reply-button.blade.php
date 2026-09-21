{{-- Кнопка ответа в ленте, во фрейме: внизу ленты (frame reply) — «Ответить {кому}», у письма (reply-{id}) — «Ответить на это».
     Нажатие грузит редактор в тот же фрейм; «Отмена» в редакторе возвращает кнопку (?cancel=1). --}}
@props(['message', 'base' => '/mail', 'frame' => 'reply'])
@php $who = ! $message->isOurs() ? \Illuminate\Support\Str::of($message->from_name ?: $message->from_email)->explode(' ')->take(2)->implode(' ') : null; @endphp
<turbo-frame id="{{ $frame }}" class="{{ $frame === 'reply' ? 'block' : 'contents' }}">
    @if ($frame === 'reply')
        <a href="{{ $base }}/{{ $message->thread_id }}/reply/{{ $message->id }}" class="btn btn-quiet"><x-ui.icon name="reply" class="size-5"/>Ответить{{ $who ? ' '.\Illuminate\Support\Str::limit($who, 32, '…') : '' }}</a>
    @else
        <a href="{{ $base }}/{{ $message->thread_id }}/reply/{{ $message->id }}" class="letter-link">Ответить на это</a>
    @endif
</turbo-frame>
