{{-- Строка почты: от кого (в отправленных — кому), тема, первые слова письма; справа дата и «📎 N».
     Нажатие — окно писем ветки; свайп влево — в архив (из архива — вернуть). Непрочитанное — полужирным.
     linked — чип ТС / предложения / кандидата (плоские списки: поиск, отправленные, архив);
     action — справа «Заявка ›» (CRM «Предложение ›»): письмо без ТС и кандидата. --}}
@props(['thread', 'base', 'park' => true, 'linked' => false, 'action' => false])
@php
    $out = $thread->latestMessage?->direction === \App\Mail\Direction::Out;
    $who = collect($thread->counterparts())->map(fn ($p) => $p['name'] ?: $p['email'])->take(2)->implode(', ') ?: $thread->account->title;
    $files = $thread->attachments_count ?? ($thread->has_attachments ? 1 : 0);
    $chip = $thread->vehicle ? $thread->vehicle->titleWithYear() : ($thread->offer ? $thread->offer->title() : ($thread->candidate?->title()));
    $when = $thread->last_message_at?->translatedFormat($thread->last_message_at->isToday() ? 'H:i' : ($thread->last_message_at->isCurrentYear() ? 'j M' : 'j M Y'));
@endphp
<x-ui.swipe id="thread-{{ $thread->id }}">
    <div class="row items-start">
        <button type="button" class="min-w-0 flex-1 text-left" data-controller="emit" data-action="emit#send" data-emit-event-param="letters:open" data-emit-url-param="{{ $base }}/{{ $thread->id }}/window">
            <div class="flex items-baseline gap-2">
                <span class="min-w-0 flex-1 truncate {{ $thread->unread_count ? 'font-medium' : '' }}">{{ $out ? 'Кому: ' : '' }}{{ $who }}@if ($thread->messages_count > 1) <span class="nums font-normal text-ink-dim">{{ $thread->messages_count }}</span>@endif</span>
                <span class="nums shrink-0 text-sm text-ink-dim">{{ $when }}</span>
            </div>
            <div class="flex items-baseline gap-2">
                <span class="min-w-0 flex-1 truncate {{ $thread->unread_count ? 'font-medium' : 'text-ink-muted' }}">{{ $thread->subject ?: '(без темы)' }}@if ($thread->latestMessage?->preview) <span class="font-normal text-ink-dim">{{ $thread->latestMessage->preview }}</span>@endif</span>
                @if ($files)<span class="nums inline-flex shrink-0 items-center gap-0.5 text-sm text-ink-dim"><x-ui.icon name="clip" class="size-3.5"/>{{ $files }}</span>@endif
            </div>
            @if ($linked && $chip)<span class="mt-1 inline-block chip max-w-full truncate">{{ $chip }}</span>@endif
        </button>
        @if ($action)
            <form method="post" action="{{ $base }}/{{ $thread->id }}/candidate" class="shrink-0 self-center">@csrf<button class="text-sm text-accent-text">{{ $park ? 'Заявка' : 'Предложение' }} ›</button></form>
        @endif
    </div>
    <x-slot:actions>
        <form method="post" action="{{ $base }}/{{ $thread->id }}/archive" data-queue>@csrf<button type="submit" class="swipe-btn swipe-btn-accent" aria-label="{{ $thread->archived_at ? 'Вернуть' : 'В архив' }}"><x-ui.icon :name="$thread->archived_at ? 'undo' : 'archive'" class="size-5"/></button></form>
    </x-slot:actions>
</x-ui.swipe>
