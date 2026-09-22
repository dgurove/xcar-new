{{-- Лента писем о ТС: по времени сверху вниз, на линии слева, как история посылки. Узел — письмо (x-mail.letter):
     этап цепочки лаймовой точкой, наше письмо серой с рамкой, письмо без нашего ответа оранжевой; автоответы и
     бухгалтерия подряд свёрнуты в один узел «3 служебных». Тема пишется строкой-разделителем, когда меняется
     (subjects). Раскрыты непрочитанные и последнее, к ним и прокрутка (focus). reply — один «Ответить {кому}» внизу,
     редактор во фрейме reply на месте кнопки. candidate — этапы для заголовков узлов, vehicle — фото уже в галерее.
     «Ч.2» того же письма (те же слова от того же адреса в сутки) — узел «Ещё файлы к письму» без текста. --}}
@props(['messages', 'base' => '/mail', 'candidate' => null, 'vehicle' => null, 'reply' => false, 'subjects' => true, 'focus' => true, 'replyOpen' => false])
@php
    use App\Mail\Chains\NodeTitle;
    use App\Mail\Extraction\Intent;
    use App\Mail\Message;
    $messages = collect($messages)->sortBy(fn (Message $m) => $m->date_at?->getTimestamp() ?? 0)->values();
    $lastId = $messages->last()?->id;
    $focusId = $focus ? ($messages->first(fn (Message $m) => ! $m->is_seen && ! $m->isOurs())?->id ?? $lastId) : null;
    $staged = collect($candidate?->stages ?? [])->pluck('message_id')->all();
    // Ждёт ответа: письмо вендора с вопросом, после которого мы ничего не писали.
    $lastOurs = $messages->last(fn (Message $m) => $m->isOurs())?->date_at;
    $asks = $messages->filter(fn (Message $m) => ! $m->isOurs() && (Intent::tryFrom((string) $m->intent)?->needsReply() ?? false) && (! $lastOurs || $m->date_at > $lastOurs))->pluck('id')->all();
    // Фото писем уже в галерее ТС: в ленте вместо сетки чип «N фото в галерее».
    $inGallery = $vehicle && $vehicle->media()->where('collection_name', 'photos')->where('custom_properties->stage', 'mail')->exists();
    $kind = fn (Message $m) => trim((in_array($m->id, $staged, true) ? 'stage ' : (in_array($m->id, $asks, true) ? 'ask ' : '')).($m->isOurs() ? 'ours' : ''));
    $service = fn (Message $m) => ! in_array($m->id, $staged, true) && in_array($m->intent, [Intent::Auto->value, Intent::Billing->value], true);
    // «Ч.2» того же письма: тот же адрес и те же слова не позже суток — продолжение, текст не повторяется, только файлы.
    $continued = [];
    $prev = null;
    foreach ($messages as $m) {
        if ($prev && $prev->from_email === $m->from_email && trim($m->ownText()) !== '' && trim($m->ownText()) === trim($prev->ownText()) && $m->date_at && $prev->date_at && $m->date_at->diffInHours($prev->date_at, true) <= 24) {
            $continued[$m->id] = true;
        } else {
            $prev = $m;
        }
    }
    // Группы: письмо или пачка служебных подряд.
    $nodes = [];
    foreach ($messages as $m) {
        if ($service($m) && $m->id !== $focusId && ($last = end($nodes)) && is_array($last) && ($last['service'] ?? false)) {
            $nodes[key($nodes)]['items'][] = $m;
            continue;
        }
        $nodes[] = $service($m) && $m->id !== $focusId ? ['service' => true, 'items' => [$m]] : $m;
    }
    // Одно служебное письмо подряд — обычный узел.
    $nodes = array_map(fn ($n) => is_array($n) && count($n['items']) === 1 ? $n['items'][0] : $n, $nodes);
    $replyTo = $reply ? ($messages->last(fn (Message $m) => ! $m->isOurs()) ?? $messages->last()) : null;
    $subject = null;
@endphp
<div {{ $attributes->merge(['class' => 'chain']) }} @if ($focusId) data-controller="chain" @endif>
    @forelse ($nodes as $node)
        @php $first = is_array($node) ? $node['items'][0] : $node; @endphp
        @if ($subjects && $first->subject_normalized !== $subject && ! isset($continued[$first->id]))
            @php $subject = $first->subject_normalized; @endphp
            <div class="chain-subject"><span class="truncate">{{ $first->subject ?: '(без темы)' }}</span></div>
        @endif
        @if (is_array($node))
            <div class="letter letter--service">
                <span class="letter-dot"></span>
                <details class="letter-body">
                    <summary class="letter-head"><span class="letter-who font-normal text-ink-muted">{{ count($node['items']) }} {{ \App\Support\Plural::of(count($node['items']), ['служебное', 'служебных', 'служебных']) }}</span><span class="letter-when">{{ end($node['items'])->date_at?->translatedFormat('j M') }}</span></summary>
                    <div class="letter-text">
                        @foreach ($node['items'] as $m)
                            <x-mail.letter :message="$m" :base="$base" :title="NodeTitle::for($m, $candidate)" :titled="NodeTitle::titled($m, $candidate)" :kind="$m->isOurs() ? 'ours' : ''" :reply="$reply" :in-gallery="$inGallery" :vehicle="$vehicle"/>
                        @endforeach
                    </div>
                </details>
            </div>
        @else
            @if (isset($continued[$node->id]))
                <x-mail.letter :message="$node" :base="$base" title="Ещё файлы к письму" :kind="$node->isOurs() ? 'ours' : ''" :open="! $node->is_seen || $node->id === $lastId" :focus="$node->id === $focusId" :reply="$reply" :in-gallery="$inGallery" :vehicle="$vehicle" continuation/>
            @else
                <x-mail.letter :message="$node" :base="$base" :title="NodeTitle::for($node, $candidate)" :titled="NodeTitle::titled($node, $candidate)" :kind="$kind($node)" :open="! $node->is_seen || $node->id === $lastId" :focus="$node->id === $focusId" :reply="$reply" :in-gallery="$inGallery" :vehicle="$vehicle"/>
            @endif
        @endif
    @empty
        <x-ui.empty>Писем нет</x-ui.empty>
    @endforelse
    @if ($replyTo)
        <div class="chain-reply"><x-mail.reply-button :message="$replyTo" :base="$base" :open="$replyOpen"/></div>
    @endif
</div>
