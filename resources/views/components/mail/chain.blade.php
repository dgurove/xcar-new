{{-- Лента писем о ТС: по времени сверху вниз, на линии слева, как история посылки. Узел — письмо (x-mail.letter):
     на линии кружок отправителя (у «ч.2» и свёрнутых — точка), этап и письмо без ответа видны по заголовку; автоответы и
     бухгалтерия подряд свёрнуты в один узел «3 служебных». Строк-тем нет: общая тема (`Mail\Topic`) — в заголовке окна
     (с 17.11.2026; `subjects` больше ничего не меняет). Раскрыто одно письмо — в фокусе (focus: id письма, иначе первое непрочитанное, иначе последнее), к нему и
     прокрутка; нажатие раскрывает другое, прежнее сворачивается, с анимацией (`chain_controller`, 13.11.2026, начальник: с
     телефона письма не раскрывались, «раскрывалось одно, остальные сворачивались»). reply — один «Ответить {кому}» внизу,
     редактор во фрейме reply на месте кнопки. candidate — этапы для заголовков узлов.
     «Ч.2» того же письма (те же слова от того же адреса в сутки) — узел «Ещё файлы к письму» без текста.
     fold — разбор письма: раскрыты только письмо-заявка и то, что ждёт ответа, прочие подряд идущие письма свёрнуты
     в узел «Ещё N писем» на своём месте в ленте.
     newest — от новых к старым, closed — все свёрнуты (переписка по машине под письмом справа во «Входящих», 17.11.2026). --}}
@props(['messages', 'base' => '/mail', 'candidate' => null, 'reply' => false, 'subjects' => true, 'focus' => true, 'replyOpen' => false, 'replyText' => null, 'fold' => false, 'newest' => false, 'closed' => false])
@php
    use App\Mail\Chains\NodeTitle;
    use App\Mail\Extraction\Intent;
    use App\Mail\Message;
    $messages = collect($messages)->sortBy(fn (Message $m) => $m->date_at?->getTimestamp() ?? 0)->values();
    // Адреса «Кому» в конверте раскрытого письма (x-mail.letter) — одним запросом на ленту.
    \Illuminate\Database\Eloquent\Collection::make($messages->all())->loadMissing('addresses');
    $lastId = $messages->last()?->id;
    // focus — id письма (строка «Из писем» открывает окно на нём) или true: первое непрочитанное, иначе последнее.
    $focusId = is_numeric($focus) && $messages->contains('id', (int) $focus) ? (int) $focus
        : ($focus ? ($messages->first(fn (Message $m) => ! $m->is_seen && ! $m->isOurs())?->id ?? $lastId) : null);
    $staged = collect($candidate?->stages ?? [])->pluck('message_id')->all();
    // Ждёт ответа — одно правило на почту, дело и ленту: ветка с `needs_reply_at`, письмо — её последнее входящее.
    $waiting = \App\Mail\Thread::whereIn('id', $messages->pluck('thread_id')->filter()->unique())->whereNotNull('needs_reply_at')->pluck('id')->all();
    $asks = $messages->filter(fn (Message $m) => in_array($m->thread_id, $waiting, true) && ! $m->isOurs())
        ->groupBy('thread_id')->map->last()->pluck('id')->all();
    // Пересланное сотрудником — письмо вендора, не наше: без серой подложки.
    $own = fn (Message $m) => $m->isOurs() && ! $m->isForwardedByStaff();
    $kind = fn (Message $m) => trim((in_array($m->id, $staged, true) ? 'stage ' : (in_array($m->id, $asks, true) ? 'ask ' : '')).($own($m) ? 'ours' : ''));
    $service = fn (Message $m) => ! in_array($m->id, $staged, true) && in_array($m->intent, [Intent::Auto->value, Intent::Billing->value], true);
    // Разбор письма: на виду только письмо-заявка, то, что ждёт ответа, и то, на чём стоит фокус.
    $keep = $fold ? (array_values(array_filter(array_unique([$candidate?->message_id, ...$asks, $focusId]))) ?: array_filter([$lastId])) : [];
    $hidden = fn (Message $m) => $m->id !== $focusId && ($service($m) || ($fold && ! in_array($m->id, $keep, true)));
    $continued = NodeTitle::continued($messages);
    // Группы: письмо или пачка свёрнутых подряд (служебные, а при fold — и всё, что не на виду).
    $nodes = [];
    foreach ($messages as $m) {
        if ($hidden($m) && ($last = end($nodes)) && is_array($last)) {
            $nodes[key($nodes)]['items'][] = $m;
            $nodes[key($nodes)]['service'] = $last['service'] && $service($m);
            continue;
        }
        $nodes[] = $hidden($m) ? ['service' => $service($m), 'items' => [$m]] : $m;
    }
    // Одно служебное письмо подряд — обычный узел; при fold свёрнутое остаётся свёрнутым и в одиночку.
    $nodes = array_map(fn ($n) => is_array($n) && count($n['items']) === 1 && ! $fold ? $n['items'][0] : $n, $nodes);
    if ($newest) {
        $nodes = array_reverse($nodes);
    }
    // Окно писем предложения: «Ответить» внизу — в ветку сделки (offer@, deal@), не парковки (09.11.2026: письмо storage@ о
    // приёме ТС свежее, и «Написать» по сделке открывало ответ со storage@). Ответ парковке — у самого её письма.
    $pool = $messages->filter(fn (Message $m) => $m->account?->scope !== \App\Mail\Scope::Park);
    $pool = $pool->isNotEmpty() ? $pool : $messages;
    // Открыли на письме (`?at=`, «Ответить» из «Ждут ответа» на парковке, 16.11.2026: по Skoda Rapid ответ из блока ч.1 уходил в
    // ветку ч.3 — последнего письма машины) — отвечаем на него, а не на самое новое.
    $atLetter = is_int($focus) ? $messages->first(fn (Message $m) => $m->id === $focus && ! $own($m)) : null;
    $replyTo = $reply ? ($atLetter ?? $pool->last(fn (Message $m) => ! $own($m)) ?? $pool->last()) : null;
@endphp
<div {{ $attributes->merge(['class' => 'chain']) }} data-controller="chain" @if ($fold) data-chain-many-value="true" @endif>
    {{-- Общая тема переписки — в заголовок окна писем вместо «Письма» (`window_controller#tools`); строк-тем внутри ленты нет
         (17.11.2026, владелец). --}}
    @if ($windowTitle = \App\Mail\Topic::of($messages))<template data-window-title>{{ $windowTitle }}</template>@endif
    @forelse ($nodes as $node)
        @php $first = is_array($node) ? $node['items'][0] : $node; @endphp
        @if (is_array($node))
            <div class="letter letter--service">
                <span class="letter-dot"></span>
                <details class="letter-body">
                    @php $n = count($node['items']); @endphp
                    <summary class="letter-head"><span class="letter-who font-normal text-ink-muted">{{ $node['service'] ? $n.' '.\App\Support\Plural::of($n, ['служебное', 'служебных', 'служебных']) : 'Ещё '.$n.' '.\App\Support\Plural::of($n, ['письмо', 'письма', 'писем']) }}</span><span class="letter-when">{{ \App\Support\Ago::html(end($node['items'])->date_at, words: false) }}</span></summary>
                    <div class="letter-text">
                        @foreach ($node['items'] as $m)
                            <x-mail.letter :common="$windowTitle" :message="$m" :replying="$m->id === $replyTo?->id" :base="$base" :title="NodeTitle::for($m, $candidate)" :titled="NodeTitle::titled($m, $candidate)" :kind="$own($m) ? 'ours' : ''" :reply="$reply"/>
                        @endforeach
                    </div>
                </details>
            </div>
        @else
            @if (isset($continued[$node->id]))
                <x-mail.letter :common="$windowTitle" :message="$node" :replying="$node->id === $replyTo?->id" :base="$base" title="Ещё файлы к письму" :kind="$own($node) ? 'ours' : ''" :open="! $closed && $node->id === ($focusId ?? $lastId)" :focus="$node->id === $focusId" :reply="$reply" continuation/>
            @else
                <x-mail.letter :common="$windowTitle" :message="$node" :replying="$node->id === $replyTo?->id" :base="$base" :title="NodeTitle::for($node, $candidate)" :titled="NodeTitle::titled($node, $candidate)" :kind="$kind($node)" :dismiss="$reply && in_array($node->id, $asks, true) && $node->thread_id !== $replyTo?->thread_id" :open="! $closed && ($fold ? (! $node->is_seen || $node->id === $focusId || in_array($node->id, $keep, true)) : $node->id === ($focusId ?? $lastId))" :focus="$node->id === $focusId" :reply="$reply"/>
            @endif
        @endif
    @empty
        <x-ui.empty>Писем нет</x-ui.empty>
    @endforelse
    @if ($replyTo)
        <div class="chain-reply"><x-mail.reply-button :message="$replyTo" :base="$base" :open="$replyOpen" :text="$replyText"/></div>
    @endif
</div>
