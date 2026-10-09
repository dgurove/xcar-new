{{-- Кнопка ответа в ленте, во фрейме: внизу ленты (frame reply) — «Ответить {кому}», у письма (reply-{id}) — «Ответить на это».
     Нажатие грузит редактор в тот же фрейм; «Отмена» в редакторе возвращает кнопку (?cancel=1). --}}
@props(['message', 'base' => '/mail', 'frame' => 'reply', 'open' => false])
@php $who = ! $message->isOurs() || $message->isForwardedByStaff() ? \Illuminate\Support\Str::of(\App\Mail\Chains\NodeTitle::who($message))->explode(' ')->take(2)->implode(' ') : null; @endphp
{{-- open — редактор грузится сразу (кнопка «Ответить» в деле ТС), кнопка внизу остаётся на время загрузки. --}}
<turbo-frame id="{{ $frame }}" class="{{ $frame === 'reply' ? 'block' : 'contents' }}" @if ($open) src="{{ $base }}/{{ $message->thread_id }}/reply/{{ $message->id }}" @endif>
    @if ($frame === 'reply')
        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ $base }}/{{ $message->thread_id }}/reply/{{ $message->id }}" class="btn btn-quiet"><x-ui.icon name="reply" class="size-5"/>Ответить{{ $who ? ' '.\Illuminate\Support\Str::limit($who, 32, '…') : '' }}</a>
            {{-- Ветка ждёт ответа, а вопрос решили иначе — снять «Требуют внимания», не архивируя письмо. --}}
            @if ($message->thread?->needs_reply_at)
                <form method="post" action="{{ $base }}/{{ $message->thread_id }}/answered" data-turbo-frame="_top" class="contents">@csrf<button class="btn btn-quiet">Ответ не нужен</button></form>
            @endif
        </div>
    @else
        <a href="{{ $base }}/{{ $message->thread_id }}/reply/{{ $message->id }}" class="letter-link">Ответить на это</a>
    @endif
</turbo-frame>
