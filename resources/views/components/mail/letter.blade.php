{{-- Письмо в ленте: свёрнуто — строка «кто, заголовок, скрепка, когда» (заголовок — этап, смысл или первые свои слова,
     непрочитанное полужирным); раскрыто — свои слова письма без цитат и подписи, вложения (фото четыре кадра и «+N»,
     документы строкой), «Исходное письмо» грузит тело в iframe только по раскрытию, «Ответить на это» — редактор
     во фрейме под письмом. kind: stage (этап, лаймовая точка), ask (без нашего ответа, оранжевая), ours (наше). --}}
@props(['message', 'base' => '/mail', 'title', 'titled' => true, 'kind' => '', 'open' => false, 'focus' => false, 'reply' => false, 'inGallery' => false, 'vehicle' => null])
@php
    use App\Mail\{ParseState, SendState};
    $m = $message;
    $ours = $m->isOurs();
    $who = \App\Mail\Chains\NodeTitle::who($m);
    $when = $m->date_at?->translatedFormat($m->date_at->isToday() ? 'H:i' : ($m->date_at->isCurrentYear() ? 'j M' : 'j M Y'));
    $files = $m->files();
    [$pictures, $documents] = $files->partition(fn ($f) => $f->isImage() && $f->mime !== 'image/svg+xml');
    $text = trim($m->ownText());
@endphp
<div class="letter{{ $kind ? ' '.implode(' ', array_map(fn ($k) => 'letter--'.$k, explode(' ', $kind))) : '' }}{{ $m->is_seen ? '' : ' letter--unread' }}" id="msg-{{ $m->id }}" @if ($focus) data-chain-target="focus" @endif>
    <span class="letter-dot"></span>
    <details class="letter-body" @if ($open) open @endif>
        <summary class="letter-head">
            <span class="letter-who">{{ $who }}</span>
            <span class="letter-title{{ $titled ? '' : ' letter-title--words' }}">{{ $title }}</span>
            @if ($files->isNotEmpty())<span class="letter-clip nums"><x-ui.icon name="clip" class="size-3.5"/>{{ $files->count() }}</span>@endif
            <span class="letter-when nums">{{ $when }}</span>
        </summary>
        <div class="letter-text">
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
            @elseif ($text !== '')
                <div class="whitespace-pre-line break-words">{{ $text }}</div>
            @else
                <div class="text-sm text-ink-muted">{{ $m->has_attachments ? 'Только вложения' : 'Без своих слов' }}</div>
            @endif

            @if ($files->isNotEmpty() && $m->filesFrozen())
                {{-- Старое письмо: файлы не скачаны (Файлы из писем с), только имена; клик достаёт файл из ящика. --}}
                <details class="mt-2">
                    <summary class="letter-link">{{ $files->count() }} {{ \App\Support\Plural::of($files->count(), ['файл в ящике', 'файла в ящике', 'файлов в ящике']) }}</summary>
                    <div class="mt-1 flex flex-wrap gap-x-3 gap-y-0.5 text-sm">
                        @foreach ($files as $file)<a href="{{ $base }}/attachments/{{ $file->id }}" target="_blank" class="truncate text-ink-muted hover:text-ink">{{ $file->filename }} <span class="text-xs text-ink-dim">{{ $file->humanSize() }}</span></a>@endforeach
                    </div>
                </details>
            @else
            @if ($pictures->isNotEmpty())
                @if ($inGallery && $vehicle)
                    <a href="/cars/{{ $vehicle->id }}/gallery" class="chip mt-3" data-turbo-frame="_top"><x-ui.icon name="photo" class="size-4"/>{{ $pictures->count() }} фото в галерее</a>
                @else
                    {{-- Четыре кадра и «+N»; нажатие на «+N» открывает остальные тут же. --}}
                    <div class="photo-grid mt-3" data-controller="more">
                        @foreach ($pictures as $i => $file)
                            <a href="{{ $base }}/attachments/{{ $file->id }}" target="_blank" class="photo-cell {{ $i >= 4 ? 'hidden' : '' }}" title="{{ $file->filename }}" @if ($i >= 4) data-more-target="item" @endif>
                                <img src="{{ $base }}/attachments/{{ $file->id }}?thumb=1" alt="" loading="lazy" class="h-full w-full object-cover">
                                @if ($i === 3 && $pictures->count() > 4)<button type="button" class="photo-more nums" data-action="more#show" data-more-target="button">+{{ $pictures->count() - 4 }}</button>@endif
                            </a>
                        @endforeach
                    </div>
                @endif
            @endif
            @if ($documents->isNotEmpty())
                <div class="mt-3 flex flex-wrap gap-2">
                    @foreach ($documents as $file)
                        <a href="{{ $base }}/attachments/{{ $file->id }}" target="_blank" class="flex max-w-full items-center gap-2 rounded-(--radius-m) bg-surface-2 p-2 pr-3">
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
