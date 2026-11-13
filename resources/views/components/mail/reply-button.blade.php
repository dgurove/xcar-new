{{-- Кнопка ответа в ленте, во фрейме: внизу ленты (frame reply) — «Ответить {кому}», у письма (reply-{id}) — «Ответить на это».
     Нажатие грузит редактор в тот же фрейм; «Отмена» в редакторе возвращает кнопку (?cancel=1). --}}
@props(['message', 'base' => '/mail', 'frame' => 'reply', 'open' => false, 'text' => null])
@php
    $who = ! $message->isOurs() || $message->isForwardedByStaff() ? \Illuminate\Support\Str::of(\App\Mail\Chains\NodeTitle::who($message))->explode(' ')->take(2)->implode(' ') : null;
    // Ветка предложения в идущей сделке (28.10.2026, владелец про 0890/046/10323/25: «Уже в работе» — продали другому,
    // «там только ответить, а отвечать нечего»): «Продал другому» закрывает сделку отказом поставщика (`DeclineBySupplier`),
    // «Ответ не нужен» — разобрано, ждём его дальше (`Position::replyHandled`).
    // Только под письмом поставщика (02.11.2026, владелец: под нашим «Выражаем интерес» кнопка рядом с «Ответить» — «че за
    // продал другому, не понимаем»): это ответ на его «уже продано», а не действие с нашим письмом.
    $deal = auth()->user()?->canManageCrm() && ! $message->isOurs() ? $message->thread?->offer?->deal : null;
    $deal = $deal?->isActive() ? $deal : null;
    $replied = $deal && $message->thread->offer->position(\App\Workflow\Track::Sale)?->letterStatus() === 'replied';
    $said = $deal && ! $message->isOurs() ? trim(\Illuminate\Support\Str::limit(preg_replace('/\s+/u', ' ', (string) $message->ownText()), 60, '…')) : '';
    // Не доставлено (02.11.2026): отвечать почтовому серверу незачем — письмо пересылают на верный адрес.
    $bounce = $message->intent === \App\Mail\Extraction\Intent::Bounce->value;
    $editor = $base.'/'.$message->thread_id.'/reply/'.$message->id.($bounce ? '?mode=forward' : '');
    // Готовый текст ответа (шаг «Переоценка», 04.11.2026) — только в открытый сразу редактор.
    $src = $open && filled($text) && ! $bounce ? $editor.'?'.http_build_query(['text' => $text]) : $editor;
@endphp
{{-- open — редактор грузится сразу (кнопка «Ответить» в деле ТС), кнопка внизу остаётся на время загрузки. --}}
<turbo-frame id="{{ $frame }}" class="{{ $frame === 'reply' ? 'block' : 'contents' }}" @if ($open) src="{{ $src }}" @endif>
    @if ($frame === 'reply')
        <div class="flex flex-wrap items-center gap-2">
            @if ($bounce)
                <a href="{{ $editor }}" class="btn btn-quiet"><x-ui.icon name="mail" class="size-5"/>Переслать</a>
            @else
                <a href="{{ $editor }}" class="btn btn-quiet"><x-ui.icon name="reply" class="size-5"/>Ответить{{ $who ? ' '.\Illuminate\Support\Str::limit($who, 32, '…') : '' }}</a>
            @endif
            {{-- Ветка ждёт ответа, а вопрос решили иначе — снять «Требуют внимания», не архивируя письмо. --}}
            @if (($message->thread?->needs_reply_at || $replied) && \App\Mail\Threads::canAnswer(auth()->user()))
                <form method="post" action="{{ $base }}/{{ $message->thread_id }}/answered" data-turbo-frame="_top" class="contents">@csrf<button class="btn btn-quiet">Ответ не нужен</button></form>
            @endif
            {{-- Поставщик уже подтвердил оплату (`prepaid_at`) — машина продана нам: «продал другому» не к месту (13.11.2026). --}}
            @if ($deal && ! $deal->prepaid_at)
                <form method="post" action="/work/deals/{{ $deal->id }}/declined" data-turbo-frame="_top" class="contents"
                      data-turbo-confirm="Поставщик продал другому?" data-turbo-confirm-text="Сделка закроется: отказ поставщика, предложение — в архив с пометкой, менеджеру придёт уведомление" data-turbo-confirm-label="Закрыть сделку">
                    @csrf
                    <input type="hidden" name="note" value="Поставщик продал другому{{ $said !== '' ? ': «'.$said.'»' : '' }}">
                    <input type="hidden" name="pickup" value="{{ $deal->offer?->vendor?->pickup_on_arrival ? 'keep' : 'drop' }}">
                    <button class="btn btn-quiet text-danger">Поставщик продал другому</button>
                </form>
            @endif
        </div>
    @else
        <a href="{{ $editor }}" class="letter-link">{{ $bounce ? 'Переслать' : 'Ответить на это' }}</a>
    @endif
</turbo-frame>
