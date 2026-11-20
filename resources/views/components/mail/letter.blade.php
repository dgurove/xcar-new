{{-- Письмо в цепочке (x-mail.chain) — единственный вид письма (17.11.2026, владелец: «объединить в одну цепочку», «везде
     одинаково»: справа во «Входящих», в окне писем предложения, сделки, ТС, ветки, на телефоне). На линии — кружок
     отправителя 24 px (у «ч.2» и у письма того же отправителя подряд — точка, repeat).
     Свёрнуто — строка: кто, этап или первые слова (непрочитанное полужирным), скрепка, дата.
     Раскрыто — вид, выбранный владельцем по макетам:
     - имя и серым адрес рядом; темы у письма нет — общая тема в заголовке окна (17.11.2026, владелец);
     - «кому: …» (адреса, не имена — 17.11.2026, владелец: «почты, а не погоняла») и дата «14.11 12:58» одним серым текстом,
       переносится; у пересылки сотрудника — кому и когда писал поставщик;
     - машина ссылкой (значок типа ТС, как в списке предложений, — логотип страховой уже у отправителя; марка, модель, год); у незаведённой цепочки «Из писем» — «Завести», у ветки без машины —
       «Привязать»;
     - справа вверху значки «Ответить» (изогнутая стрелка) и «Переслать» (прямая), как в Spark, и «⋯» (x-mail.letter-menu);
     - текст на белом без пустых строк, подпись — «› Подпись» (`BodyRenderer::own flat`), «Не ушло / Отправить снова»;
     - файлы — x-mail.letter-files; под письмом фрейм ответа `reply-pane-{id}` (editor — открыть редактор сразу:
       «Ответить поставщику» в шаге «Переоценка»).
     reply — есть действия (значки и меню); kind: stage (этап), ask (без нашего ответа), ours (наше) — оттенки строки;
     context — pane (справа во «Входящих») или window; car=false — окно писем самой машины (предложения, ТС) или цепочки «Из писем» со своим «Завести»: ссылка на неё же лишняя; dismiss — «Ответ не нужен» в меню (ждёт ответа в соседней ветке). --}}
{{-- continuation — «ч.2» того же письма (те же слова, другие файлы): текст не повторяется, только вложения. --}}
@props(['message', 'base' => '/mail', 'title', 'titled' => true, 'kind' => '', 'open' => false, 'focus' => false, 'reply' => false, 'continuation' => false, 'dismiss' => false, 'common' => null, 'context' => 'window', 'editor' => null, 'car' => true, 'repeat' => false])
@php
    use App\Mail\{ParseState, SendState};
    use App\Mail\Chains\NodeTitle;
    $m = $message;
    $ours = $m->isOurs();
    $who = NodeTitle::who($m);
    $files = $m->files();
    $text = trim($m->shownText());
    $from = NodeTitle::email($m);
    $to = $m->addresses->whereIn('kind', [\App\Mail\AddressKind::To->value, \App\Mail\AddressKind::Cc->value])->sortBy('position')
        ->map(fn ($a) => mb_strtolower((string) ($a->email ?: $a->name)))->filter()->unique()->values();
    // Пересылка сотрудника — кому и когда писал сам поставщик, из шапки пересылки (17.11.2026, владелец: «не „переслал“ и не
    // наш ящик, а оригинальное кому и во сколько»).
    $head = $m->isForwardedByStaff() ? \App\Mail\Extraction\QuotationStripper::forwardedHead($m->text_body ?: app(\App\Mail\Parser::class)->htmlToText((string) $m->html_body)) : null;
    if ($head && $head['to']) {
        $to = collect($head['to']);
    }
    $sentAt = $head['date'] ?? $m->date_at;
    $park = $base === '/mail';
    $thread = $m->thread;
    $threadPark = $thread?->account?->scope === \App\Mail\Scope::Park;
    // Машина письма — по стороне его ветки: предложение в CRM, ТС на парковке; на чужую сторону — ссылкой без Turbo.
    $showCar = $car;
    $car = ! $showCar ? null : match (true) {
        (bool) $thread?->offer => ['href' => $thread->offer->crmUrl(), 'cat' => $thread->offer->category(), 'name' => $thread->offer->titleWithYear(), 'cross' => $park],
        (bool) $thread?->vehicle => ['href' => \App\Support\Surface::Park->url('/cars/'.$thread->vehicle->id), 'cat' => $thread->vehicle->category ?? \App\Cars\Category::guess($thread->vehicle->titleWithYear()) ?? \App\Cars\Category::Passenger, 'name' => $thread->vehicle->titleWithYear(), 'cross' => ! $park],
        default => null,
    };
    $chainNew = ! $car && $thread?->candidate?->state === \App\Mail\CandidateState::New ? $thread->candidate : null;
    $sameSide = $threadPark === $park;
    $bounce = $m->intent === \App\Mail\Extraction\Intent::Bounce->value;
    $reply = $reply && $thread && ! $continuation;
    $editorUrl = $base.'/'.$m->thread_id.'/reply/'.$m->id;
    $unread = ! $m->is_seen && $m->outside;
@endphp
<div class="letter{{ $kind ? ' '.implode(' ', array_map(fn ($k) => 'letter--'.$k, explode(' ', $kind))) : '' }}{{ $repeat && ! $continuation ? ' letter--repeat' : '' }}{{ $m->is_seen ? '' : ' letter--unread' }}" id="msg-{{ $m->id }}" data-message="{{ $m->id }}" @if ($unread) data-unread @endif @if ($focus) data-chain-target="focus" @endif data-controller="unhide">
    {{-- Узел на линии — кружок отправителя 24 px; продолжение «ч.2» — точка, это то же письмо; подряд от того же отправителя
         (repeat) — точка, как в строках писем сделки (`x-mail.rail`), раскрыли — снова кружок (17.11.2026, владелец). --}}
    @if ($continuation)<span class="letter-dot"></span>@else
        @if ($repeat)<span class="letter-dot"></span>@endif
        <span class="letter-face"><x-mail.sender-avatar :message="$m" :size="24"/></span>
    @endif
    <details class="letter-body{{ $reply ? ' letter-body--acts' : '' }}" @if ($open) open @endif>
        <summary class="letter-head">
            <span class="letter-who" title="{{ $from }}">{{ $who }}@unless ($continuation)<span class="letter-from"> {{ $from }}</span>@endunless</span>
            <span class="letter-title{{ $titled ? '' : ' letter-title--words' }}">{{ $title }}</span>
            @if ($files->isNotEmpty())<span class="letter-clip nums"><x-ui.icon name="clip" class="size-3.5"/>{{ $files->count() }}</span>@endif
            <span class="letter-when nums">{{ \App\Support\Ago::stamp($m->date_at) }}</span>
        </summary>
        @if ($reply)
            {{-- Значки без подписей, как в Spark (17.11.2026, владелец выбрал из сравнения): ответ — изогнутая, пересылка — прямая.
                 Недоставку не «отвечают» почтовому серверу — пересылают на верный адрес. --}}
            <div class="letter-acts">
                <a href="{{ $editorUrl }}{{ $bounce ? '?mode=forward' : '' }}" class="letter-ib" data-turbo-frame="reply-pane-{{ $m->id }}" title="{{ $bounce ? 'Переслать' : 'Ответить' }}" aria-label="{{ $bounce ? 'Переслать' : 'Ответить' }}"><x-ui.icon name="mail-reply" class="size-5"/></a>
                <a href="{{ $editorUrl }}?mode=forward" class="letter-ib" data-turbo-frame="reply-pane-{{ $m->id }}" title="Переслать" aria-label="Переслать"><x-ui.icon name="mail-forward" class="size-5"/></a>
                <x-mail.letter-menu :message="$m" :base="$base" :context="$context" :park="$park" :dismiss="$dismiss"/>
            </div>
        @endif
        @unless ($continuation)
            {{-- Кому и дата — одним серым текстом: не влезло — переносится, дата целиком. --}}
            <div class="letter-env">
                @if ($to->isNotEmpty())<span>кому: {{ $to->take(3)->join(', ') }}{{ $to->count() > 3 ? ' и ещё '.($to->count() - 3) : '' }}</span>@endif
                <span class="nums whitespace-nowrap">{{ \App\Support\Ago::at($sentAt, true) }}</span>
            </div>
            @if ($car)
                <a href="{{ $car['href'] }}" class="letter-car" data-turbo-frame="_top" @if ($car['cross']) data-turbo="false" @elseif ($thread?->offer) {!! \App\Support\DealSheet::attrs() !!} @endif><x-ui.cat-icon :category="$car['cat']"/><span class="truncate">{{ $car['name'] }}</span><x-ui.icon name="chevron-right" class="size-3.5 shrink-0"/></a>
            @elseif ($showCar && $chainNew && $sameSide)
                @if ($park)<a href="/requests/new?candidate={{ $chainNew->id }}" class="letter-car letter-car--do" data-turbo-frame="_top">Завести: {{ $chainNew->title() }}</a>
                @else<form method="post" action="/offers/from-mail/{{ $chainNew->id }}/create" class="contents" data-turbo-frame="_top">@csrf<button class="letter-car letter-car--do">Завести: {{ $chainNew->title() }}</button></form>@endif
            @elseif ($showCar && $thread && $sameSide && $reply)
                <button type="button" class="letter-car letter-car--do" data-action="unhide#show">{{ $park ? 'Привязать к ТС' : 'Привязать к предложению' }}</button>
            @endif
            @if ($thread && $reply)
                <form method="post" action="{{ $base }}/{{ $thread->id }}/link" class="letter-link-form" data-turbo-frame="_top" data-unhide-target="block" hidden>
                    @csrf
                    @if ($park)
                        <div class="min-w-0 flex-1"><x-ui.combobox name="vehicle_id" label="Транспортное средство" url="/reference/cars" :value="$thread->vehicle_id" :text="$thread->vehicle?->titleWithYear()"/></div>
                    @else
                        <x-ui.field name="number" label="Номер предложения" :value="$thread->offer?->number" span="flex-1"/>
                    @endif
                    <x-ui.button size="sm">Привязать</x-ui.button>
                    @if ($car)<x-ui.button variant="ghost" size="sm" name="{{ $park ? 'vehicle_id' : 'number' }}" value="">Отвязать</x-ui.button>@endif
                </form>
            @endif
        @endunless
        <div class="letter-text">
            @if ($ours && $m->send_state && $m->send_state !== SendState::Sent)
                <div class="mb-3 flex flex-wrap items-center gap-2">
                    <span class="{{ $m->send_state === SendState::Failed ? 'text-danger' : 'text-urgent' }} font-medium">{{ $m->send_state->label() }}</span>
                    @if ($m->send_error)<span class="text-sm text-danger">{{ $m->send_error }}</span>@endif
                    {{-- Не ушло — отправить снова прямо из письма (04.11.2026: в окне была только красная причина). --}}
                    @if ($m->send_state === SendState::Failed)<form method="post" action="{{ $base }}/messages/{{ $m->id }}/retry" data-turbo-frame="_top">@csrf<x-ui.button size="sm" variant="secondary">Отправить снова</x-ui.button></form>@endif
                </div>
            @endif
            @if ($m->parse_state === ParseState::Failed)
                {{-- Разобрать снова — здесь же (было только на странице ветки старым видом, 17.11.2026). --}}
                <div class="mb-3 flex flex-wrap items-center gap-2"><span class="text-sm text-danger">Не разобралось</span><span class="text-sm text-ink-muted">{{ $m->parse_error }}</span>
                    <form method="post" action="{{ $base }}/messages/{{ $m->id }}/parse" data-turbo-frame="_top">@csrf<x-ui.button size="sm" variant="secondary">Разобрать снова</x-ui.button></form></div>
            @elseif ($m->parse_state === ParseState::Pending)
                <div class="text-sm text-ink-muted">Разбирается…</div>
            @elseif (! $continuation)
                @if ($note = $m->forwardNote())
                    {{-- Примечание сотрудника над пересылкой — репликой, как заметка в истории. --}}
                    <div class="history-note mb-3"><div class="text-sm text-ink-muted">{{ NodeTitle::forwarder($m) }}</div><div class="whitespace-pre-line break-words">{{ $note }}</div></div>
                @endif
                {{-- Свои слова как прислали — с приветствием, цветом и жирным, без цитаты прежней переписки и пустых строк; подпись
                     свёрнута. Песочница iframe грузится, только когда письмо раскрыто (`loading="lazy"`). --}}
                @php $doc = app(\App\Mail\BodyRenderer::class)->own($m, $base, flat: true); @endphp
                @if ($doc)
                    <div data-controller="frame" class="letter-own overflow-hidden">
                        <iframe sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox" srcdoc="{{ $doc }}" title="Письмо" loading="lazy" class="block w-full" style="height:22px" data-frame-target="frame" data-action="load->frame#fit"></iframe>
                    </div>
                @elseif ($text !== '')
                    <div class="whitespace-pre-line break-words">{{ $text }}</div>
                @else
                    <div class="text-sm text-ink-muted">{{ $files->isNotEmpty() ? 'Только вложения' : 'Без своих слов' }}</div>
                @endif
            @endif
            <x-mail.letter-files :message="$m" :base="$base"/>
            @if ($reply)
                {{-- Ответ и пересылка — редактором здесь, под письмом; «Отмена» снова пустой фрейм. --}}
                <turbo-frame id="reply-pane-{{ $m->id }}" class="mt-4 block empty:hidden" @if ($editor) src="{{ $editor }}" @endif></turbo-frame>
            @endif
        </div>
    </details>
</div>
