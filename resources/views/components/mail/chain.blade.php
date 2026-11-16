{{-- Цепочка писем — одна на все места (17.11.2026, владелец: «объединить в одну цепочку», «везде одинаково»): справа во
     «Входящих», в окне писем предложения, сделки, ТС, ветки, цепочки «Из писем», в разборе письма. Узел — письмо
     (x-mail.letter) на линии с кружком отправителя (у «ч.2» и свёрнутых пачек — точка); этап и письмо без ответа видны по
     заголовку; автоответы и бухгалтерия подряд свёрнуты в один узел «3 служебных». От новых к старым (newest, по умолчанию).
     Раскрыто одно письмо в полном виде — в фокусе (focus: id письма, иначе первое непрочитанное, иначе самое новое), к нему
     прокрутка; нажатие раскрывает другое, прежнее сворачивается в строку (`chain_controller`). Строк-тем нет: общая тема
     (`Mail\Topic`) — в заголовке окна. Нижнего ряда «Ответить / Показать менеджеру» нет — значки и «⋯» у самого письма;
     reply-open — редактор сразу открыт у письма, на которое отвечаем (с готовым текстом reply-text). candidate — этапы для
     заголовков. «Ч.2» того же письма — узел «Ещё файлы к письму» без текста. fold — разбор письма: раскрыты письмо-заявка и
     то, что ждёт ответа, прочие подряд свёрнуты в «Ещё N писем». closed — все свёрнуты. context — pane или window
     (x-mail.letter-menu). --}}
@props(['messages', 'base' => '/mail', 'candidate' => null, 'reply' => false, 'subjects' => false, 'focus' => true, 'replyOpen' => false, 'replyText' => null, 'fold' => false, 'newest' => true, 'closed' => false, 'context' => 'window', 'car' => true])
@php
    use App\Mail\Chains\NodeTitle;
    use App\Mail\Extraction\Intent;
    use App\Mail\Message;
    $messages = collect($messages)->sortBy(fn (Message $m) => $m->date_at?->getTimestamp() ?? 0)->values();
    // Шапка раскрытого письма (x-mail.letter): адреса, вложения, автор, машина ветки — одним запросом на ленту.
    \Illuminate\Database\Eloquent\Collection::make($messages->all())->loadMissing(['addresses', 'attachments', 'author', 'account',
        'thread.account', 'thread.offer.vendor', 'thread.vehicle.vendor', 'thread.candidate', 'thread.vendor']);
    // Одна ветка — один экземпляр: «⋯» письма спрашивает письма ветки (выбор вендора) — раз на ветку, а не на каждое письмо.
    $sharedThreads = [];
    foreach ($messages as $m) {
        if ($m->thread_id && $m->relationLoaded('thread') && $m->thread) {
            $m->setRelation('thread', $sharedThreads[$m->thread_id] ??= $m->thread);
        }
    }
    // Лента — вся переписка этих веток: письма ветки берутся из неё, а не запросом на каждую.
    foreach ($sharedThreads as $id => $t) {
        if (! $t->relationLoaded('messages')) {
            $t->setRelation('messages', \Illuminate\Database\Eloquent\Collection::make($messages->where('thread_id', $id)->values()->all()));
        }
    }
    $lastId = $messages->last()?->id;
    // focus — id письма (строка списка, «Из писем» открывает окно на нём) или true: первое непрочитанное, иначе самое новое.
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
        // Свежие сверху — и внутри свёрнутой пачки служебных тоже.
        $nodes = array_map(fn ($n) => is_array($n) ? ['service' => $n['service'], 'items' => array_reverse($n['items'])] : $n, array_reverse($nodes));
    }
    // Окно писем предложения: «Ответить» внизу — в ветку сделки (offer@, deal@), не парковки (09.11.2026: письмо storage@ о
    // приёме ТС свежее, и «Написать» по сделке открывало ответ со storage@). Ответ парковке — у самого её письма.
    $pool = $messages->filter(fn (Message $m) => $m->account?->scope !== \App\Mail\Scope::Park);
    $pool = $pool->isNotEmpty() ? $pool : $messages;
    // Открыли на письме (`?at=`, «Ответить» из «Ждут ответа» на парковке, 16.11.2026: по Skoda Rapid ответ из блока ч.1 уходил в
    // ветку ч.3 — последнего письма машины) — отвечаем на него, а не на самое новое.
    $atLetter = is_int($focus) ? $messages->first(fn (Message $m) => $m->id === $focus && ! $own($m)) : null;
    $replyTo = $reply && $replyOpen ? ($atLetter ?? $pool->last(fn (Message $m) => ! $own($m)) ?? $pool->last()) : null;
    // Окно открыли сразу на ответ — редактор у этого письма открыт, оно раскрыто (готовый текст — только в ответ, не в пересылку).
    $editor = null;
    if ($replyTo) {
        $focusId = $replyTo->id;
        $bounce = $replyTo->intent === Intent::Bounce->value;
        $editor = $base.'/'.$replyTo->thread_id.'/reply/'.$replyTo->id.($bounce ? '?mode=forward' : (filled($replyText) ? '?'.http_build_query(['text' => $replyText]) : ''));
    }
@endphp
<div {{ $attributes->merge(['class' => 'chain chain--full']) }} data-controller="chain" @if ($fold) data-chain-many-value="true" @endif>
    {{-- Общая тема переписки — в заголовок окна писем вместо «Письма» (`window_controller#tools`); строк-тем внутри ленты нет
         (17.11.2026, владелец). --}}
    @if ($windowTitle = \App\Mail\Topic::of($messages))<template data-window-title>{{ $windowTitle }}</template>@endif
    @php $prevWho = null; @endphp
    @forelse ($nodes as $node)
        @php
            $first = is_array($node) ? $node['items'][0] : $node;
            // Подряд от того же отправителя — точка вместо кружка (как в строках писем сделки); пачка служебных цепь рвёт.
            // «ч.2» — то же письмо: цепь «подряд» она не рвёт и не начинает.
            $who = is_array($node) ? null : (isset($continued[$node->id]) ? $prevWho : NodeTitle::email($node));
            $repeat = $who !== null && $who === $prevWho;
            $prevWho = $who;
        @endphp
        @if (is_array($node))
            <div class="letter letter--service">
                <span class="letter-dot"></span>
                <details class="letter-body">
                    @php $n = count($node['items']); @endphp
                    <summary class="letter-head"><span class="letter-who font-normal text-ink-muted">{{ $node['service'] ? $n.' '.\App\Support\Plural::of($n, ['служебное', 'служебных', 'служебных']) : 'Ещё '.$n.' '.\App\Support\Plural::of($n, ['письмо', 'письма', 'писем']) }}</span><span class="letter-when">{{ \App\Support\Ago::html(end($node['items'])->date_at, words: false) }}</span></summary>
                    <div class="letter-text">
                        @foreach ($node['items'] as $m)
                            <x-mail.letter :common="$windowTitle" :message="$m" :base="$base" :context="$context" :car="$car" :title="NodeTitle::for($m, $candidate)" :titled="NodeTitle::titled($m, $candidate)" :kind="$own($m) ? 'ours' : ''" :reply="$reply" :editor="$m->id === $replyTo?->id ? $editor : null"/>
                        @endforeach
                    </div>
                </details>
            </div>
        @else
            @if (isset($continued[$node->id]))
                <x-mail.letter :common="$windowTitle" :message="$node" :base="$base" :context="$context" :car="$car" title="Ещё файлы к письму" :kind="$own($node) ? 'ours' : ''" :open="! $closed && $node->id === ($focusId ?? $lastId)" :focus="$node->id === $focusId" :reply="$reply" continuation/>
            @else
                <x-mail.letter :common="$windowTitle" :message="$node" :base="$base" :context="$context" :car="$car" :editor="$node->id === $replyTo?->id ? $editor : null" :title="NodeTitle::for($node, $candidate)" :titled="NodeTitle::titled($node, $candidate)" :kind="$kind($node)" :repeat="$repeat" :dismiss="$reply && in_array($node->id, $asks, true)" :open="! $closed && ($fold ? (! $node->is_seen || $node->id === $focusId || in_array($node->id, $keep, true)) : $node->id === ($focusId ?? $lastId))" :focus="$node->id === $focusId" :reply="$reply"/>
            @endif
        @endif
    @empty
        <x-ui.empty>Писем нет</x-ui.empty>
    @endforelse
</div>
