{{-- Строка почты: от кого, тема, первые слова письма; справа дата, под ней ТС или предложение и «📎 N».
     Нажатие — окно писем ветки; свайп влево — прочитано / не прочитано. --}}
@php $who = collect($thread->counterparts())->map(fn ($p) => $p['name'] ?: $p['email'])->take(3)->implode(', ') ?: $thread->account->title; $files = $thread->attachments_count ?? ($thread->has_attachments ? 1 : 0); $linked = $thread->vehicle ?? $thread->offer; @endphp
<x-ui.swipe id="thread-{{ $thread->id }}">
    <button type="button" class="row w-full items-start text-left" data-controller="emit" data-action="emit#send" data-emit-event-param="letters:open" data-emit-url-param="{{ $base }}/{{ $thread->id }}/window">
        <div class="min-w-0 flex-1">
            <div class="truncate {{ $thread->unread_count ? 'font-medium' : '' }}">{{ $who }}@if ($thread->messages_count > 1) <span class="nums font-normal text-ink-dim">{{ $thread->messages_count }}</span>@endif</div>
            <div class="truncate {{ $thread->unread_count ? 'font-medium' : '' }}">{{ $thread->subject ?: '(без темы)' }}</div>
            @if ($thread->latestMessage?->preview)<div class="truncate text-sm text-ink-muted">{{ $thread->latestMessage->preview }}</div>@endif
        </div>
        <div class="flex shrink-0 flex-col items-end gap-1 text-sm text-ink-dim">
            <span class="nums">{{ $thread->last_message_at?->translatedFormat($thread->last_message_at->isToday() ? 'H:i' : 'j M') }}</span>
            <span class="flex items-center gap-1">
                @if ($linked)<span class="chip max-w-[10rem] truncate">{{ $thread->vehicle ? $thread->vehicle->titleWithYear() : $thread->offer->title() }}</span>@endif
                @if ($files)<span class="chip nums"><x-ui.icon name="clip" class="size-3.5"/>{{ $files }}</span>@endif
                @if (($accounts?->count() ?? 0) > 1 && empty($slug))<span class="chip">{{ $thread->account->title }}</span>@endif
            </span>
        </div>
    </button>
    <x-slot:actions>
        <form method="post" action="{{ $base }}/{{ $thread->id }}/read" data-queue>@csrf<button type="submit" class="swipe-btn swipe-btn-accent" aria-label="{{ $thread->unread_count ? 'Прочитано' : 'Не прочитано' }}"><x-ui.icon :name="$thread->unread_count ? 'eye' : 'eye-off'" class="size-5"/></button></form>
    </x-slot:actions>
</x-ui.swipe>
