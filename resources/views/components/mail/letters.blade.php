{{-- Письма кандидата строками: от кого, когда, тема, вложения; строка ведёт в ветку. --}}
@props(['messages', 'mail'])
<div class="flex flex-col divide-y divide-line/40">
    @foreach ($messages as $m)
        @php $files = $m->attachments->reject->is_inline; @endphp
        <a href="{{ $mail }}/{{ $m->thread_id }}#msg-{{ $m->id }}" class="flex items-start gap-3 py-2 text-sm">
            <div class="min-w-0 flex-1">
                <div class="flex items-baseline gap-2"><span class="truncate font-medium">{{ $m->from_name ?: $m->from_email }}</span><span class="nums shrink-0 text-ink-dim">{{ $m->date_at?->translatedFormat('j M, H:i') }}</span></div>
                <div class="truncate text-ink-muted">{{ $m->subject }}</div>
            </div>
            @if ($files->isNotEmpty())<span class="chip nums shrink-0"><x-ui.icon name="clip" class="size-3.5"/> {{ $files->count() }}</span>@endif
        </a>
    @endforeach
</div>
