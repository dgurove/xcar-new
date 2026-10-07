{{-- Письмо в ленте: свёрнуто — строка «кто, заголовок, скрепка, когда» (заголовок — этап, смысл или первые свои слова,
     непрочитанное полужирным); раскрыто — свои слова письма без цитат и подписи, вложения (фото лентой, документы
     строкой, открываются в шторке документов), «Исходное письмо» грузит тело в iframe только по раскрытию, «Ответить на это» — редактор
     во фрейме под письмом. На линии — кружок отправителя (x-mail.sender-avatar); kind: stage (этап),
     ask (без нашего ответа) — заголовок светлее, ours (наше) — фон у текста. --}}
{{-- continuation — «ч.2» того же письма (те же слова, другие файлы): текст не повторяется, только вложения. --}}
@props(['message', 'base' => '/mail', 'title', 'titled' => true, 'kind' => '', 'open' => false, 'focus' => false, 'reply' => false, 'continuation' => false])
@php
    use App\Mail\{ParseState, SendState};
    use App\Mail\Chains\NodeTitle;
    $m = $message;
    $ours = $m->isOurs();
    $who = NodeTitle::who($m);
    $when = $m->date_at?->translatedFormat($m->date_at->isToday() ? 'H:i' : ($m->date_at->isCurrentYear() ? 'j M' : 'j M Y'));
    $files = $m->files();
    [$pictures, $documents] = $files->partition(fn ($f) => $f->isImage() && $f->mime !== 'image/svg+xml');
    $text = trim($m->ownText());
    // Конверт раскрытого письма: от кого — адресом, полная дата, кому и копия (владелец 07.10.2026: почту «нигде
    // невозможно посмотреть и скопировать»). Адреса ленте грузит x-mail.chain одним запросом.
    $from = NodeTitle::email($m);
    $to = $m->addresses->whereIn('kind', [\App\Mail\AddressKind::To->value, \App\Mail\AddressKind::Cc->value])->sortBy('position')->pluck('email')->unique()->values();
    $forwarder = $m->isForwardedByStaff() ? mb_strtolower((string) $m->from_email) : null;
@endphp
<div class="letter{{ $kind ? ' '.implode(' ', array_map(fn ($k) => 'letter--'.$k, explode(' ', $kind))) : '' }}{{ $m->is_seen ? '' : ' letter--unread' }}" id="msg-{{ $m->id }}" @if ($focus) data-chain-target="focus" @endif>
    {{-- Узел на линии — кружок отправителя; продолжение «ч.2» — точка, это то же письмо. --}}
    @if ($continuation)<span class="letter-dot"></span>@else<span class="letter-face"><x-mail.sender-avatar :message="$m" :size="24"/></span>@endif
    <details class="letter-body" @if ($open) open @endif>
        <summary class="letter-head">
            <span class="letter-who" title="{{ $from }}">{{ $who }}</span>
            <span class="letter-title{{ $titled ? '' : ' letter-title--words' }}">{{ $title }}</span>
            @if ($files->isNotEmpty())<span class="letter-clip nums"><x-ui.icon name="clip" class="size-3.5"/>{{ $files->count() }}</span>@endif
            {{-- Раскрыто — полная дата («21 сентября, 14:26»), свёрнуто — короткая. --}}
            <span class="letter-when nums"><span class="letter-when-short">{{ $when }}</span><span class="letter-when-full">{{ $m->date_at?->isoFormat($m->date_at->isCurrentYear() ? 'D MMMM, H:mm' : 'D MMMM YYYY, H:mm') }}</span></span>
        </summary>
        <div class="letter-text">
            @unless ($continuation)
                {{-- Конверт: подпись через пробел, адреса текстом через запятую; каждый берётся нажатием. --}}
                <div class="letter-env">
                    <div class="letter-env-row"><span class="letter-env-label">От</span> <x-mail.address :email="$from"/></div>
                    @if ($forwarder && $forwarder !== $from)<div class="letter-env-row"><span class="letter-env-label">Переслал</span> <x-mail.address :email="$forwarder"/></div>@endif
                    @if ($to->isNotEmpty())
                        <div class="letter-env-row"><span class="letter-env-label">Кому</span> <span class="letter-env-to">@foreach ($to->take(3) as $address)<x-mail.address :email="$address"/>@endforeach @if ($to->count() > 3)<details class="letter-env-more letter-env-to"><summary>и ещё {{ $to->count() - 3 }}</summary>@foreach ($to->slice(3) as $address)<x-mail.address :email="$address"/>@endforeach</details>@endif</span></div>
                    @endif
                </div>
            @endunless
            @if ($ours && $m->send_state && $m->send_state !== SendState::Sent)
                <div class="mb-2 flex flex-wrap items-center gap-2">
                    <span class="chip {{ $m->send_state === SendState::Failed ? 'bg-danger-soft text-danger' : 'bg-urgent-soft text-urgent' }}">{{ $m->send_state?->label() }}</span>
                    @if ($m->send_error)<span class="text-sm text-danger">{{ $m->send_error }}</span>@endif
                </div>
            @endif
            @if ($m->parse_state === ParseState::Failed)
                <div class="mb-2 flex flex-wrap items-center gap-2"><span class="chip bg-danger-soft text-danger">Не разобралось</span><span class="text-sm text-ink-muted">{{ $m->parse_error }}</span></div>
            @elseif ($m->parse_state === ParseState::Pending)
                <div class="text-sm text-ink-muted">Разбирается…</div>
            @elseif ($continuation)
            @else
            @if ($note = $m->forwardNote())
                {{-- Примечание сотрудника над пересылкой — репликой, как заметка в истории. --}}
                <div class="history-note mb-2"><div class="text-sm text-ink-muted">{{ \App\Mail\Chains\NodeTitle::forwarder($m) }}</div><div class="whitespace-pre-line break-words">{{ $note }}</div></div>
            @endif
            @if ($text !== '')
                <div class="whitespace-pre-line break-words">{{ $text }}</div>
            @else
                <div class="text-sm text-ink-muted">{{ $m->has_attachments ? 'Только вложения' : 'Без своих слов' }}</div>
            @endif
            @endif

            @if ($files->isNotEmpty() && $m->filesFrozen())
                {{-- Старое письмо: файлы не скачаны (Файлы из писем с), только имена; клик достаёт файл из ящика. --}}
                <details class="mt-2">
                    <summary class="letter-link">{{ $files->count() }} {{ \App\Support\Plural::of($files->count(), ['файл в ящике', 'файла в ящике', 'файлов в ящике']) }}</summary>
                    <div class="mt-1 flex flex-wrap gap-x-3 gap-y-0.5 text-sm">
                        @foreach ($files as $file)<a href="{{ $base }}/attachments/{{ $file->id }}" target="_blank" class="truncate text-ink-muted hover:text-ink" data-doc="{{ \App\Support\Docs::type($file->mime, $file->filename) }}" data-doc-name="{{ \App\Support\Docs::label($file->filename) }}" title="{{ $file->filename }}">{{ $file->filename }} <span class="text-xs text-ink-dim">{{ $file->humanSize() }}</span></a>@endforeach
                    </div>
                </details>
            @else
            @if ($pictures->isNotEmpty())<x-mail.photo-strip :files="$pictures" :base="$base"/>@endif
            @if ($documents->isNotEmpty())
                <div class="mt-3 flex flex-wrap gap-2">
                    @foreach ($documents as $file)
                        <a href="{{ $base }}/attachments/{{ $file->id }}" target="_blank" class="flex max-w-full items-center gap-2 rounded-(--radius-m) bg-surface-2 p-2 pr-3" data-doc="{{ \App\Support\Docs::type($file->mime, $file->filename) }}" data-doc-name="{{ \App\Support\Docs::label($file->filename) }}" title="{{ $file->filename }}">
                            <x-ui.file-icon :name="$file->filename" :mime="$file->mime"/>
                            <span class="min-w-0"><span class="block truncate text-sm">{{ $file->filename }}</span><span class="text-xs text-ink-muted">{{ $file->humanSize() }}</span></span>
                        </a>
                    @endforeach
                </div>
            @endif
            @endif

            <div data-controller="unhide">
                <div class="letter-foot">
                    <button type="button" class="letter-link" data-action="unhide#show" data-unhide-target="trigger">Исходное письмо</button>
                    @if ($reply)<x-mail.reply-button :message="$m" :base="$base" :frame="'reply-'.$m->id"/>@endif
                </div>
                <turbo-frame id="body-{{ $m->id }}" src="{{ $base }}/messages/{{ $m->id }}/body" loading="lazy" target="_top" class="mt-2" hidden data-unhide-target="block"><x-ui.skeleton :rows="2"/></turbo-frame>
            </div>
        </div>
    </details>
</div>
