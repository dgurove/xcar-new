{{-- Письмо, открытое справа во «Входящих» и «Отправленных» (17.11.2026, макет выбран владельцем: «вся эта шапка лишняя»,
     «как в Spark»): без панели и темы (тема — в строке списка). Кружок, имя, ниже серым одним текстом адрес, «кому» и дата
     (не влезло — переносится), ниже машина ссылкой; справа значки «Ответить» (изогнутая стрелка) и «Переслать» (прямая) и
     «⋯» — «В архив», «Не прочитано», «Привязать к ТС», вендор, исходное письмо, страница ветки. Кнопок внизу нет: ответ
     открывается редактором под письмом (фрейм reply-pane-{id}). Текст и вложения — с отступом под имя, как в Gmail;
     документы — карточками в две колонки, фото — одной карточкой «N фото» без миниатюр (владелец: «фотки почти юзлесс, они
     и так уходят в предложение»). Остальная переписка по машине — цепочкой под письмом (`admin/mail/pane`). --}}
@props(['message', 'thread', 'base', 'park' => false])
@php
    use App\Mail\{ParseState, SendState};
    use App\Mail\Chains\NodeTitle;
    $m = $message;
    $ours = $m->isOurs();
    $files = $m->files();
    [$pictures, $documents] = $files->partition(fn ($f) => $f->isImage() && $f->mime !== 'image/svg+xml');
    $text = trim($m->shownText());
    $from = NodeTitle::email($m);
    $to = $m->addresses->whereIn('kind', [\App\Mail\AddressKind::To->value, \App\Mail\AddressKind::Cc->value])->sortBy('position')
        ->map(fn ($a) => $a->name ?: $a->email)->unique()->values();
    $linked = $park ? $thread->vehicle : $thread->offer;
    $car = match (true) {
        (bool) ($park ? null : $thread->offer) => ['href' => $thread->offer->crmUrl(), 'vendor' => $thread->offer->vendor, 'name' => $thread->offer->titleWithYear()],
        (bool) ($park ? $thread->vehicle : null) => ['href' => \App\Support\Surface::Park->url('/cars/'.$thread->vehicle->id), 'vendor' => $thread->vehicle->vendor, 'name' => $thread->vehicle->titleWithYear()],
        default => null,
    };
    $chain = ! $linked ? $thread->candidate : null;
    $bounce = $m->intent === \App\Mail\Extraction\Intent::Bounce->value;
    $editor = $base.'/'.$m->thread_id.'/reply/'.$m->id;
    $menu = 'pane-menu-'.$m->id;
    $pick = \App\Mail\VendorPick::of(auth()->user(), $park ? \App\Mail\Scope::Park : \App\Mail\Scope::Offers, $base, $thread, $thread->messages, $thread->candidate);
    $archives = $documents->filter(fn ($f) => \App\Support\Docs::archive($f));
    $documents = $documents->diff($archives);
    $count = fn (int $n, array $w) => $n.' '.\App\Support\Plural::of($n, $w);
@endphp
<article class="pane-letter" id="pane-letter-{{ $m->id }}" data-controller="unhide">
    <header class="pane-head">
        <x-mail.sender-avatar :message="$m" :size="40"/>
        <div class="min-w-0 flex-1">
            {{-- Имя и справа от него адрес (17.11.2026, владелец: «почту справа от имени, а не под ним»); ниже кому и дата — одним
                 серым текстом: не влезло — переносится, дата целиком. --}}
            <div class="pane-who"><b>{{ NodeTitle::who($m) }}</b> <span class="pane-from">{{ $from }}</span></div>
            <div class="pane-env">
                @if ($to->isNotEmpty())<span>кому: {{ $to->take(3)->join(', ') }}{{ $to->count() > 3 ? ' и ещё '.($to->count() - 3) : '' }}</span>@endif
                <span class="nums whitespace-nowrap">{{ \App\Support\Ago::at($m->date_at, true) }}</span>
            </div>
            @if ($car)
                <a href="{{ $car['href'] }}" class="pane-car">@if ($car['vendor'])<x-vendor.logo :vendor="$car['vendor']"/>@endif<span class="truncate">{{ $car['name'] }}</span><x-ui.icon name="chevron-right" class="size-3.5 shrink-0"/></a>
            @elseif ($chain && $chain->state === \App\Mail\CandidateState::New)
                @if ($park)<a href="/requests/new?candidate={{ $chain->id }}" class="pane-car pane-car--do">Завести: {{ $chain->title() }}</a>
                @else<form method="post" action="/offers/from-mail/{{ $chain->id }}/create" class="contents">@csrf<button class="pane-car pane-car--do">Завести: {{ $chain->title() }}</button></form>@endif
            @elseif (! $linked)
                <button type="button" class="pane-car pane-car--do" data-action="unhide#show">{{ $park ? 'Привязать к ТС' : 'Привязать к предложению' }}</button>
            @endif
        </div>
        <div class="pane-acts">
            {{-- Значки без подписей, как в Spark (17.11.2026, владелец выбрал из сравнения): ответ — изогнутая, пересылка — прямая. --}}
            <a href="{{ $editor }}{{ $bounce ? '?mode=forward' : '' }}" class="pane-ib" data-turbo-frame="reply-pane-{{ $m->id }}" title="{{ $bounce ? 'Переслать' : 'Ответить' }}" aria-label="{{ $bounce ? 'Переслать' : 'Ответить' }}"><x-ui.icon name="mail-reply" class="size-5"/></a>
            <a href="{{ $editor }}?mode=forward" class="pane-ib" data-turbo-frame="reply-pane-{{ $m->id }}" title="Переслать" aria-label="Переслать"><x-ui.icon name="mail-forward" class="size-5"/></a>
            <x-ui.more :id="$menu">
                <div class="menu-group">
                    <form method="post" action="{{ $base }}/{{ $thread->id }}/archive" data-turbo-frame="detail" class="contents">@csrf<button class="menu-item w-full" role="menuitem">{{ $thread->archived_at ? 'Вернуть из архива' : 'В архив' }}</button></form>
                    @if (! $ours || $m->isForwardedByStaff())
                        <form method="post" action="{{ $base }}/messages/{{ $m->id }}/seen" data-turbo-frame="_top" class="contents">@csrf<input type="hidden" name="read" value="0"><button class="menu-item w-full" role="menuitem">Не прочитано</button></form>
                    @endif
                </div>
                <div class="menu-group">
                    <button type="button" class="menu-item w-full" role="menuitem" data-action="unhide#show">{{ $linked ? 'Перепривязать' : ($park ? 'Привязать к ТС' : 'Привязать к предложению') }}</button>
                    @if ($pick)<button type="button" class="menu-item w-full" role="menuitem" data-controller="emit" data-action="emit#send"
                        data-emit-event-param="vendor-pick:open" data-emit-url-param="{{ $pick['url'] }}" data-emit-sender-param="{{ $pick['sender'] }}" data-emit-name-param="{{ $pick['name'] }}" data-emit-domain-param="{{ $pick['domain'] }}">{{ $thread->vendor ? 'Сменить вендора' : 'Выбрать вендора' }}</button>@endif
                    <a href="{{ $base }}/messages/{{ $m->id }}/body" class="menu-item" role="menuitem" data-turbo-frame="body-pane-{{ $m->id }}">Исходное письмо</a>
                    @unless ($park)<a href="{{ $base }}/{{ $thread->id }}" class="menu-item" role="menuitem">Страница ветки</a>@endunless
                </div>
            </x-ui.more>
        </div>
    </header>
    <form method="post" action="{{ $base }}/{{ $thread->id }}/link" class="pane-link" data-unhide-target="block" hidden>
        @csrf
        @if ($park)
            <div class="min-w-0 flex-1"><x-ui.combobox name="vehicle_id" label="Транспортное средство" url="/reference/cars" :value="$thread->vehicle_id" :text="$thread->vehicle?->titleWithYear()"/></div>
        @else
            <x-ui.field name="number" label="Номер предложения" :value="$thread->offer?->number" span="flex-1"/>
        @endif
        <x-ui.button size="sm">Привязать</x-ui.button>
        @if ($linked)<x-ui.button variant="ghost" size="sm" name="{{ $park ? 'vehicle_id' : 'number' }}" value="">Отвязать</x-ui.button>@endif
    </form>

    <div class="pane-body">
        @if ($ours && $m->send_state && $m->send_state !== SendState::Sent)
            <div class="mb-3 flex flex-wrap items-center gap-2">
                <span class="{{ $m->send_state === SendState::Failed ? 'text-danger' : 'text-urgent' }} font-medium">{{ $m->send_state->label() }}</span>
                @if ($m->send_error)<span class="text-sm text-danger">{{ $m->send_error }}</span>@endif
                @if ($m->send_state === SendState::Failed)<form method="post" action="{{ $base }}/messages/{{ $m->id }}/retry" data-turbo-frame="_top">@csrf<x-ui.button size="sm" variant="secondary">Отправить снова</x-ui.button></form>@endif
            </div>
        @endif
        @if ($m->parse_state === ParseState::Failed)
            <div class="mb-2 text-sm text-ink-muted"><span class="text-danger">Не разобралось</span> {{ $m->parse_error }}</div>
        @elseif ($m->parse_state === ParseState::Pending)
            <div class="text-sm text-ink-muted">Разбирается…</div>
        @else
            @if ($note = $m->forwardNote())
                <div class="history-note mb-3"><div class="text-sm text-ink-muted">{{ NodeTitle::forwarder($m) }}</div><div class="whitespace-pre-line break-words">{{ $note }}</div></div>
            @endif
            @php
                $own = app(\App\Mail\BodyRenderer::class);
                $doc = $own->own($m, $base, flat: true);
            @endphp
            @if ($doc)
                <div data-controller="frame" class="letter-own overflow-hidden">
                    <iframe sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox" srcdoc="{{ $doc }}" title="Письмо" class="block w-full" style="height:60px" data-frame-target="frame" data-action="load->frame#fit"></iframe>
                </div>
            @elseif ($text !== '')
                <div class="whitespace-pre-line break-words">{{ $text }}</div>
            @else
                <div class="text-sm text-ink-muted">{{ $files->isNotEmpty() ? 'Только вложения' : 'Без своих слов' }}</div>
            @endif
        @endif
        <turbo-frame id="body-pane-{{ $m->id }}" target="_top" class="mt-3 block"></turbo-frame>

        @if ($files->isNotEmpty())
            <div class="pane-files">
                <div class="pane-files-head">
                    <span>{{ $documents->count() + $archives->count() ? $count($documents->count() + $archives->count(), ['документ', 'документа', 'документов']) : '' }}{{ $documents->count() + $archives->count() && $pictures->isNotEmpty() ? ' и ' : '' }}{{ $pictures->isNotEmpty() ? $pictures->count().' фото' : '' }}</span>
                    @unless ($m->filesFrozen())<a href="{{ $base }}/messages/{{ $m->id }}/files.zip" class="pane-files-all" download data-turbo="false">Скачать все</a>@endunless
                </div>
                @if ($m->filesFrozen())
                    {{-- Старое письмо: файлы не скачаны (Файлы из писем с), только имена; нажатие достаёт файл из ящика. --}}
                    <div class="pane-docs">
                        @foreach ($files as $file)<a href="{{ $base }}/attachments/{{ $file->id }}" target="_blank" class="pane-doc" data-doc="{{ \App\Support\Docs::type($file->mime, $file->filename) }}" data-doc-name="{{ \App\Support\Docs::label($file->filename) }}" title="{{ $file->filename }}"><x-ui.file-icon :name="$file->filename" :mime="$file->mime"/><span class="min-w-0 truncate">{{ $file->filename }}</span></a>@endforeach
                    </div>
                @else
                    <div class="pane-docs">
                        @foreach ($documents as $file)
                            <a href="{{ $base }}/attachments/{{ $file->id }}" target="_blank" class="pane-doc" data-doc="{{ \App\Support\Docs::type($file->mime, $file->filename) }}" data-doc-name="{{ \App\Support\Docs::label($file->filename) }}" title="{{ $file->filename }}">
                                <x-ui.file-icon :name="$file->filename" :mime="$file->mime" :bytes="$file->size"/><span class="min-w-0 truncate">{{ $file->filename }}</span>
                            </a>
                        @endforeach
                        {{-- Фото — одной карточкой, без миниатюр: просмотр грузит кадр, когда до него долистали. --}}
                        @if ($pictures->isNotEmpty())
                            <div class="contents" data-controller="photos" data-photos-readonly-value="true">
                                <button type="button" class="pane-doc" data-action="click->photos#open">
                                    <span class="pane-photos-icon"><x-ui.icon name="photo" class="size-5"/></span><span class="min-w-0 flex-1 truncate text-left">{{ $pictures->count() }} фото</span><x-ui.icon name="chevron-right" class="size-4 shrink-0 text-ink-muted"/>
                                </button>
                                @foreach ($pictures as $file)<img data-full="{{ $base }}/attachments/{{ $file->id }}?large=1" data-download="{{ $base }}/attachments/{{ $file->id }}" alt="" hidden>@endforeach
                            </div>
                        @endif
                    </div>
                    @foreach ($archives as $file)<x-mail.archive :file="$file" :entries="\App\Support\Docs::archive($file)" :base="$base"/>@endforeach
                @endif
            </div>
        @endif
        {{-- Ответ и пересылка — редактором здесь, под письмом; «Отмена» снова пустой фрейм. --}}
        <turbo-frame id="reply-pane-{{ $m->id }}" class="mt-4 block empty:hidden"></turbo-frame>
    </div>
</article>
