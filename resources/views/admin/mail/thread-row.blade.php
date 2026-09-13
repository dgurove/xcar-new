@php $who = collect($thread->counterparts())->map(fn ($p) => $p['name'] ?: $p['email'])->take(3)->implode(', ') ?: $thread->account->title; @endphp
<x-ui.swipe id="thread-{{ $thread->id }}">
    <a href="{{ $base }}/{{ $thread->id }}" class="row items-start">
        @if ($thread->unread_count)<span class="mt-2 size-2 shrink-0 rounded-full bg-accent"></span>@endif
        <div class="min-w-0 flex-1">
            <div class="flex items-baseline gap-2">
                <span class="truncate {{ $thread->unread_count ? 'font-medium' : '' }}">{{ $who }}</span>
                @if ($thread->messages_count > 1)<span class="text-sm text-ink-dim">{{ $thread->messages_count }}</span>@endif
                <span class="ml-auto shrink-0 text-sm text-ink-dim">{{ $thread->last_message_at?->translatedFormat($thread->last_message_at->isToday() ? 'H:i' : 'j M') }}</span>
            </div>
            <div class="truncate {{ $thread->unread_count ? '' : 'text-ink-muted' }}">{{ $thread->subject ?: '(без темы)' }}</div>
            <div class="mt-1 flex items-center gap-2 text-sm text-ink-muted">
                @if ($thread->has_attachments)<x-ui.icon name="clip" class="size-4 shrink-0"/>@endif
                @if ($thread->offer)<span class="chip">№ {{ $thread->offer->number }} · {{ $thread->offer->title() }}</span>@endif
                @if ($thread->vehicle)<span class="chip">{{ $thread->vehicle->titleWithYear() }}</span>@endif
                @if (($accounts?->count() ?? 0) > 1 && empty($slug))<span class="chip">{{ $thread->account->title }}</span>@endif
            </div>
        </div>
    </a>
    <x-slot:actions>
        <form method="post" action="{{ $base }}/{{ $thread->id }}/prochitano">@csrf<button type="submit" class="swipe-btn swipe-btn-accent" aria-label="{{ $thread->unread_count ? 'Прочитано' : 'Не прочитано' }}"><x-ui.icon :name="$thread->unread_count ? 'eye' : 'eye-off'" class="size-5"/></button></form>
    </x-slot:actions>
</x-ui.swipe>
