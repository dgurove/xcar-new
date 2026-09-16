@foreach ($messages as $m)
    @php $mine = $m->isMine($user); $system = $m->author_kind === \App\Chats\AuthorKind::System; @endphp
    <div class="flex {{ $system ? 'justify-center' : ($mine ? 'justify-end' : 'justify-start') }}" data-seq="{{ $m->seq }}">
        <div class="max-w-[85%] {{ $system ? 'text-center text-sm text-ink-muted' : 'rounded-(--radius-l) px-3.5 py-2.5 '.($mine ? 'bg-accent-soft' : 'bg-surface-3') }}">
            @if (!$system && !$mine)<div class="mb-0.5 text-xs text-ink-muted">{{ $m->author?->name ?? ($m->author_kind === \App\Chats\AuthorKind::Staff ? 'XCar' : ($chat->guest_name ?? '')) }}</div>@endif
            @if ($m->text)<div class="whitespace-pre-line break-words">{{ $m->text }}</div>@endif
            @if ($m->files->isNotEmpty())
                <div class="mt-1.5 flex flex-wrap gap-1.5">
                    @foreach ($m->files as $f)
                        <a href="/chats/{{ $m->chat_id }}/files/{{ $f->id }}" target="_blank" class="block overflow-hidden rounded-(--radius-s)">
                            @if ($f->isImage())<img src="/chats/{{ $m->chat_id }}/files/{{ $f->id }}" alt="" class="max-h-48 rounded-(--radius-s)" loading="lazy">
                            @else<span class="flex items-center gap-1.5 bg-surface-2 px-2 py-1.5 text-sm"><x-ui.icon name="file" class="size-4"/>{{ $f->name }}</span>@endif
                        </a>
                    @endforeach
                </div>
            @endif
            @unless ($system)<div class="mt-0.5 text-right text-[11px] text-ink-dim">{{ $m->created_at->translatedFormat($m->created_at->isToday() ? 'H:i' : 'j M H:i') }}</div>@endunless
        </div>
    </div>
@endforeach
