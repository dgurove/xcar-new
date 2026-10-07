{{-- Лента писем о ТС: по времени сверху вниз, на линии слева, как история посылки. Узел — письмо (x-mail.letter):
     на линии кружок отправителя (у «ч.2» и свёрнутых — точка), этап и письмо без ответа видны по заголовку; автоответы и
     бухгалтерия подряд свёрнуты в один узел «3 служебных». Тема пишется строкой-разделителем, когда меняется
     (subjects). Раскрыты непрочитанные и последнее, к ним и прокрутка (focus; id письма — раскрыто и в фокусе оно). reply — один «Ответить {кому}» внизу,
     редактор во фрейме reply на месте кнопки. candidate — этапы для заголовков узлов.
     «Ч.2» того же письма (те же слова от того же адреса в сутки) — узел «Ещё файлы к письму» без текста.
     fold — разбор письма: раскрыты только письмо-заявка и то, что ждёт ответа, прочие подряд идущие письма свёрнуты
     в узел «Ещё N писем» на своём месте в ленте. --}}
@props(['messages', 'base' => '/mail', 'candidate' => null, 'reply' => false, 'subjects' => true, 'focus' => true, 'replyOpen' => false, 'fold' => false])
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
    $replyTo = $reply ? ($messages->last(fn (Message $m) => ! $own($m)) ?? $messages->last()) : null;
    $subject = null;
    // Тема строкой — без «Re:/Fwd:», у пересылки сотрудника — та, что в цитате: это письмо вендора.
    $topic = fn (Message $m) => ($m->isForwardedByStaff() ? \App\Mail\Extraction\QuotationStripper::forwardedSubject($m->text_body) : null)
        ?? trim((string) preg_replace('/^\s*(?:(?:fwd|fw|re|пересылка|пересл)\s*:\s*)+/ui', '', (string) $m->subject));
@endphp
<div {{ $attributes->merge(['class' => 'chain']) }} @if ($focusId) data-controller="chain" @endif>
    @forelse ($nodes as $node)
        @php $first = is_array($node) ? $node['items'][0] : $node; @endphp
        @if ($subjects && $first->subject_normalized !== $subject && ! isset($continued[$first->id]))
            @php $subject = $first->subject_normalized; @endphp
            <div class="chain-subject"><span class="truncate">{{ $topic($first) ?: '(без темы)' }}</span></div>
        @endif
        @if (is_array($node))
            <div class="letter letter--service">
                <span class="letter-dot"></span>
                <details class="letter-body">
                    @php $n = count($node['items']); @endphp
                    <summary class="letter-head"><span class="letter-who font-normal text-ink-muted">{{ $node['service'] ? $n.' '.\App\Support\Plural::of($n, ['служебное', 'служебных', 'служебных']) : 'Ещё '.$n.' '.\App\Support\Plural::of($n, ['письмо', 'письма', 'писем']) }}</span><span class="letter-when">{{ end($node['items'])->date_at?->translatedFormat('j M') }}</span></summary>
                    <div class="letter-text">
                        @foreach ($node['items'] as $m)
                            <x-mail.letter :message="$m" :base="$base" :title="NodeTitle::for($m, $candidate)" :titled="NodeTitle::titled($m, $candidate)" :kind="$own($m) ? 'ours' : ''" :reply="$reply"/>
                        @endforeach
                    </div>
                </details>
            </div>
        @else
            @if (isset($continued[$node->id]))
                <x-mail.letter :message="$node" :base="$base" title="Ещё файлы к письму" :kind="$own($node) ? 'ours' : ''" :open="! $node->is_seen || $node->id === $lastId || $node->id === $focusId" :focus="$node->id === $focusId" :reply="$reply" continuation/>
            @else
                <x-mail.letter :message="$node" :base="$base" :title="NodeTitle::for($node, $candidate)" :titled="NodeTitle::titled($node, $candidate)" :kind="$kind($node)" :open="! $node->is_seen || $node->id === $focusId || ($fold ? in_array($node->id, $keep, true) : $node->id === $lastId)" :focus="$node->id === $focusId" :reply="$reply"/>
            @endif
        @endif
    @empty
        <x-ui.empty>Писем нет</x-ui.empty>
    @endforelse
    @if ($replyTo)
        <div class="chain-reply"><x-mail.reply-button :message="$replyTo" :base="$base" :open="$replyOpen"/></div>
    @endif
</div>
