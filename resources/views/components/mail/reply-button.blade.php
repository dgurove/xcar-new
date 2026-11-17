{{-- Кнопка ответа в ленте, во фрейме: внизу ленты (frame reply) — ряд «Ответить» слева, «Показать менеджеру» и «⋯» справа, у письма (reply-{id}) — «Ответить на это».
     Нажатие грузит редактор в тот же фрейм; «Отмена» в редакторе возвращает кнопку (?cancel=1). --}}
@props(['message', 'base' => '/mail', 'frame' => 'reply', 'open' => false, 'text' => null])
@php
    // Ветка предложения в идущей сделке (28.10.2026, владелец про 0890/046/10323/25: «Уже в работе» — продали другому,
    // «там только ответить, а отвечать нечего»): «Продал другому» закрывает сделку отказом поставщика (`DeclineBySupplier`),
    // «Ответ не нужен» — разобрано, ждём его дальше (`Position::replyHandled`).
    // Только под письмом поставщика (02.11.2026, владелец: под нашим «Выражаем интерес» кнопка рядом с «Ответить» — «че за
    // продал другому, не понимаем»): это ответ на его «уже продано», а не действие с нашим письмом.
    $deal = auth()->user()?->canEditDeals() && ! $message->isOurs() ? $message->thread?->offer?->deal : null;
    $deal = $deal?->isActive() ? $deal : null;
    $replied = $deal && $message->thread->offer->position(\App\Workflow\Track::Sale)?->letterStatus() === 'replied';
    $said = $deal && ! $message->isOurs() ? trim(\Illuminate\Support\Str::limit(preg_replace('/\s+/u', ' ', (string) $message->shownText()), 60, '…')) : '';
    // Не доставлено (02.11.2026): отвечать почтовому серверу незачем — письмо пересылают на верный адрес.
    $bounce = $message->intent === \App\Mail\Extraction\Intent::Bounce->value;
    $editor = $base.'/'.$message->thread_id.'/reply/'.$message->id.($bounce ? '?mode=forward' : '');
    // Готовый текст ответа (шаг «Переоценка», 04.11.2026) — только в открытый сразу редактор.
    $src = $open && filled($text) && ! $bounce ? $editor.'?'.http_build_query(['text' => $text]) : $editor;
@endphp
{{-- open — редактор грузится сразу (кнопка «Ответить» в деле ТС), кнопка внизу остаётся на время загрузки. --}}
<turbo-frame id="{{ $frame }}" class="{{ $frame === 'reply' ? 'block' : 'contents' }}" @if ($open) src="{{ $src }}" @endif>
    @if ($frame === 'reply')
        {{-- Ряд мелких кнопок, как в Gmail (16.11.2026, владелец: большие плашки «уродливые», выбрал вариант Б): «Ответить» и
             «Показать менеджеру» — подписью, редкое и опасное — в «⋯». Имени ящика у «Ответить» нет: «implementation» — ящик, а не человек. --}}
        @php
            $noReply = ($message->thread?->needs_reply_at || $replied) && \App\Mail\Threads::canAnswer(auth()->user());
            // Поставщик уже подтвердил оплату (`prepaid_at`) — машина продана нам: «продал другому» не к месту (13.11.2026).
            // Закрыть сделку отказом — решение админа (17.11.2026): модератору «Показать менеджеру» есть, «Продал другому» — нет.
            $declined = $deal && ! $deal->prepaid_at && auth()->user()->canManageCrm();
        @endphp
        <div class="reply-acts">
            <a href="{{ $editor }}" class="btn btn-quiet"><x-ui.icon :name="$bounce ? 'mail' : 'reply'" class="size-4"/>{{ $bounce ? 'Переслать' : 'Ответить' }}</a>
            {{-- «Показать менеджеру» это письмо — та же шторка, что у строки письма в шаге, с отмеченным текстом письма. --}}
            @if ($deal?->buyer_id)
                @php
                    $shown = collect([$message]);
                    $sheetId = 'disclose-letter-'.$message->id;
                    $proposals = \App\Offers\Disclosures::proposals($deal, $shown, $message->id);
                @endphp
                {{-- Справа вместе с «⋯» (16.11.2026, владелец): слева — ответ, справа — что ещё сделать с письмом. --}}
                <div class="contents" data-controller="sheet">
                    <button type="button" class="btn btn-quiet reply-acts-end" data-action="sheet#open" aria-controls="{{ $sheetId }}"><x-ui.icon name="user" class="size-4"/>Показать менеджеру</button>
                    @include('admin.deals.reply-review-sheet', ['id' => $sheetId, 'deal' => $deal, 'letters' => $shown, 'proposals' => $proposals,
                        'step' => \App\Offers\Actions\Disclose::stepExit($deal, $shown), 'picked' => [], 'message' => $message, 'top' => true])
                </div>
            @endif
            @if ($noReply || $declined)
                <x-ui.more :id="'reply-more-'.$message->id" :class="$deal?->buyer_id ? 'reply-more' : 'reply-more reply-acts-end'">
                    @if ($noReply)
                        {{-- Ветка ждёт ответа, а вопрос решили иначе — снять «Требуют внимания», не архивируя письмо. --}}
                        <div class="menu-group">
                            <form method="post" action="{{ $base }}/{{ $message->thread_id }}/answered" data-turbo-frame="_top" class="contents">@csrf<button class="menu-item w-full" role="menuitem">Ответ не нужен</button></form>
                        </div>
                    @endif
                    @if ($declined)
                        <div class="menu-group">
                            <form method="post" action="/work/deals/{{ $deal->id }}/declined" data-turbo-frame="_top" class="contents"
                                  data-turbo-confirm="Поставщик продал другому?" data-turbo-confirm-text="Сделка закроется: отказ поставщика, предложение — в архив с пометкой, менеджеру придёт уведомление" data-turbo-confirm-label="Закрыть сделку">
                                @csrf
                                <input type="hidden" name="note" value="Поставщик продал другому{{ $said !== '' ? ': «'.$said.'»' : '' }}">
                                <input type="hidden" name="pickup" value="{{ $deal->offer?->vendor?->pickup_on_arrival ? 'keep' : 'drop' }}">
                                <button class="menu-item w-full text-danger" role="menuitem">Поставщик продал другому</button>
                            </form>
                        </div>
                    @endif
                </x-ui.more>
            @endif
        </div>
    @else
        <a href="{{ $editor }}" class="letter-link">{{ $bounce ? 'Переслать' : 'Ответить на это' }}</a>
    @endif
</turbo-frame>
