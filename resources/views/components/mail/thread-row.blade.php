{{-- Строка почты: кружок отправителя (наше письмо — сотрудник), имя, справа от имени тег смысла последнего входящего
     («Заявка», «Продано»; наше последнее — «Мы ответили», пересланное сотрудником с личного ящика — «От сотрудника»)
     и дата; второй строкой тема с первыми словами, скрепка и число писем — теги над ними не висят, иначе темы не видно.
     Оранжевое — только «Ждёт ответа» (вопрос, документы, осмотр без нашего ответа). Непрочитанное — полужирным с оранжевой
     точкой: обработанная ветка (ответили, завели, «Сделано», в архив) читается сама. Нажатие — окно писем ветки; свайп влево — в архив (из архива — вернуть).
     linked — плоский список (поиск, «Ждут ответа», «Прочее», архив): там нет заголовка секции, поэтому вендор и ТС — чипами в строке. --}}
@props(['thread', 'base', 'park' => true, 'linked' => false])
@php
    use App\Mail\Extraction\Intent;
    $last = $thread->latestMessage;
    $out = $last?->direction === \App\Mail\Direction::Out;
    $ours = $last?->isOurs() ?? false;
    $party = collect($thread->counterparts())->first();
    $who = $party ? ($party['name'] ?: $party['email']) : $thread->account->title;
    $files = $thread->attachments_count ?? ($thread->has_attachments ? 1 : 0);
    $intent = ! $ours ? Intent::tryFrom((string) ($thread->latestIncoming?->intent ?? $last?->intent)) : null;
    $tag = $ours ? ($out ? 'Мы ответили' : 'От сотрудника') : $intent?->short();
    $tone = $ours ? 'tag-dim' : ($intent === Intent::Intake ? 'tag-accent' : (in_array($intent, [Intent::Billing, Intent::Auto], true) ? 'tag-dim' : ''));
    // Оранжевое — только то, что ждёт нас; признак один на все экраны — `Threads::refresh` → `needs_reply_at`.
    $waits = $thread->needs_reply_at !== null;
    $chip = $thread->vehicle ? $thread->vehicle->titleWithYear() : ($thread->offer ? $thread->offer->title() : ($thread->candidate?->title()));
    $when = $thread->last_message_at?->translatedFormat($thread->last_message_at->isToday() ? 'H:i' : ($thread->last_message_at->isCurrentYear() ? 'j M' : 'j M Y'));
    $unread = $thread->unread_count > 0;
@endphp
<x-ui.swipe id="thread-{{ $thread->id }}" data-search-row>
    <div class="row items-start">
        @if ($ours && $last?->author)
            <x-ui.avatar :user="$last->author" :size="32"/>
        @elseif ($ours)
            <x-chat.avatar :user="null" :size="32"/>
        @else
            <x-ui.avatar :name="$party['name'] ?? null" :email="$party['email'] ?? null" :size="32"/>
        @endif
        <button type="button" class="min-w-0 flex-1 text-left" data-controller="emit" data-action="emit#send" data-emit-event-param="letters:open" data-emit-url-param="{{ $base }}/{{ $thread->id }}/window">
            <div class="flex items-baseline gap-2">
                <span class="min-w-0 max-w-max shrink truncate {{ $unread ? 'font-medium' : '' }}">@if ($unread)<span class="mr-1.5 inline-block size-2 rounded-full bg-urgent align-[1px]"></span>@endif{{ $out ? 'Кому: ' : '' }}{{ $who }}</span>
                @if ($waits)<span class="tag tag-urgent shrink-0">Ждёт ответа</span>@endif
                @if ($tag)<span class="tag {{ $tone }} shrink-0">{{ $tag }}</span>@endif
                @if ($linked && $thread->vendor)<span class="tag hidden shrink-0 sm:inline-flex">{{ $thread->vendor->name }}</span>@endif
                <span class="nums ml-auto shrink-0 text-xs text-ink-dim">{{ $when }}</span>
            </div>
            <div class="mt-0.5 flex items-center gap-2">
                <span class="min-w-0 flex-1 truncate {{ $unread ? 'font-medium' : 'text-ink-muted' }}">{{ $thread->subject ?: '(без темы)' }}@if ($last?->preview) <span class="font-normal text-ink-dim">{{ $last->preview }}</span>@endif</span>
                <span class="nums inline-flex shrink-0 items-center gap-1.5 text-xs text-ink-dim">@if ($files)<span class="inline-flex items-center gap-0.5"><x-ui.icon name="clip" class="size-3.5"/>{{ $files }}</span>@endif @if ($thread->messages_count > 1)<span>{{ $thread->messages_count }}</span>@endif</span>
            </div>
            @if ($linked && $chip)<span class="mt-1 inline-flex max-w-full items-center gap-1 tag truncate"><x-ui.icon :name="$thread->vehicle ? 'car' : 'mail'" class="size-3 text-ink-dim"/>{{ $chip }}</span>@endif
        </button>
    </div>
    <x-slot:actions>
        <form method="post" action="{{ $base }}/{{ $thread->id }}/archive" data-queue>@csrf<button type="submit" class="swipe-btn swipe-btn-accent" aria-label="{{ $thread->archived_at ? 'Вернуть' : 'В архив' }}"><x-ui.icon :name="$thread->archived_at ? 'undo' : 'archive'" class="size-5"/></button></form>
    </x-slot:actions>
</x-ui.swipe>
