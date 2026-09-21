{{-- Одно письмо целиком на странице ветки CRM: шапка (от кого, кому, когда), флажок, статус отправки, тело в песочнице
     iframe, вложения, «Ответить», «Всем», «Переслать». В окнах письма идут лентой x-mail.chain. --}}
@props(['message', 'base' => '/mail', 'document' => null])
@php
    use App\Mail\{Direction, ParseState, SendState, BodyRenderer};
    $out = $message->direction === Direction::Out;
    $renderer = app(BodyRenderer::class);
    $document ??= $renderer->document($message, request()->boolean('images'), $base);
@endphp
<x-ui.card {{ $attributes->merge(['class' => $out ? 'md:ml-8' : 'md:mr-8']) }} id="msg-{{ $message->id }}">
    <div class="mb-3 flex items-start gap-3">
        <div class="min-w-0 flex-1">
            <div class="flex flex-wrap items-baseline gap-x-2">
                <span class="font-medium">{{ $out ? ($message->author?->name ?? $message->from_name ?? 'Мы') : ($message->from_name ?: $message->from_email) }}</span>
                @if (!$out && $message->from_name)<span class="text-sm text-ink-muted">{{ $message->from_email }}</span>@endif
            </div>
            <div class="text-sm text-ink-muted">Кому: {{ $message->to_preview ?: '—' }}</div>
        </div>
        <div class="flex shrink-0 items-center gap-1">
            <span class="text-sm text-ink-dim">{{ $message->date_at?->translatedFormat('j M, H:i') }}</span>
            <form method="post" action="{{ $base }}/messages/{{ $message->id }}/flag">@csrf<button class="btn btn-ghost btn-s px-1.5 {{ $message->is_flagged ? 'text-urgent' : 'text-ink-dim' }}" aria-label="Отметить"><x-ui.icon name="flag" class="size-5"/></button></form>
        </div>
    </div>

    @if ($out && $message->send_state !== SendState::Sent)
        <div class="mb-3 flex flex-wrap items-center gap-2">
            <span class="chip {{ $message->send_state === SendState::Failed ? 'bg-danger-soft text-danger' : 'bg-urgent-soft text-urgent' }}">{{ $message->send_state?->label() }}</span>
            @if ($message->send_error)<span class="text-sm text-danger">{{ $message->send_error }}</span>@endif
            @if ($message->send_state === SendState::Failed)<form method="post" action="{{ $base }}/messages/{{ $message->id }}/retry">@csrf<x-ui.button size="sm" variant="secondary">Отправить снова</x-ui.button></form>@endif
        </div>
    @elseif ($out && !$message->appended_to_sent_at)
        <div class="mb-3 text-sm text-ink-muted">Ушло, но копия в «Отправленных» ящика не сохранилась.</div>
    @endif

    @if ($message->parse_state === ParseState::Failed)
        <div class="mb-3 flex flex-wrap items-center gap-2">
            <span class="chip bg-danger-soft text-danger">Не разобралось</span>
            <span class="text-sm text-ink-muted">{{ $message->parse_error }}</span>
            <form method="post" action="{{ $base }}/messages/{{ $message->id }}/parse">@csrf<x-ui.button size="sm" variant="secondary">Разобрать снова</x-ui.button></form>
        </div>
    @elseif ($message->parse_state === ParseState::Pending)
        <div class="mb-3 text-sm text-ink-muted">Разбирается…</div>
    @else
        <div data-controller="frame" class="overflow-hidden rounded-(--radius-l) bg-white">
            <iframe sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox" srcdoc="{{ $document }}" title="Письмо" class="block w-full" style="height:120px" data-frame-target="frame" data-action="load->frame#fit"></iframe>
        </div>
        @if (!request()->boolean('images') && $renderer->hasRemoteImages($message))
            <a href="{{ request()->fullUrlWithQuery(['images' => 1]) }}#msg-{{ $message->id }}" class="btn btn-ghost btn-s mt-2">Показать картинки</a>
        @endif
    @endif

    @php [$pictures, $documents] = $message->files()->partition(fn ($f) => $f->isImage() && $f->mime !== 'image/svg+xml'); @endphp
    @if ($pictures->isNotEmpty())
        {{-- Фото письма сеткой квадратов, миниатюры считает ?thumb; клик — оригинал. --}}
        <div class="photo-grid mt-3">
            @foreach ($pictures as $file)
                <a href="{{ $base }}/attachments/{{ $file->id }}" target="_blank" class="photo-cell" title="{{ $file->filename }}"><img src="{{ $base }}/attachments/{{ $file->id }}?thumb=1" alt="" loading="lazy" class="h-full w-full object-cover"></a>
            @endforeach
        </div>
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

    <div class="mt-4 flex flex-wrap gap-2">
        <a href="{{ $base }}/{{ $message->thread_id }}/reply/{{ $message->id }}" class="btn btn-quiet btn-s">Ответить</a>
        <a href="{{ $base }}/{{ $message->thread_id }}/reply/{{ $message->id }}?mode=all" class="btn btn-ghost btn-s">Всем</a>
        <a href="{{ $base }}/{{ $message->thread_id }}/reply/{{ $message->id }}?mode=forward" class="btn btn-ghost btn-s">Переслать</a>
    </div>
</x-ui.card>
