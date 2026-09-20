{{-- Строка таблицы почты: от кого, тема с первыми словами письма, ТС или предложение, «📎 N», когда.
     Нажатие — окно писем этой ветки (letters:open). Непрочитанное — полужирным, точки нет. --}}
@props(['thread', 'base', 'accounts' => null, 'slug' => null])
@php
    $who = collect($thread->counterparts())->map(fn ($p) => $p['name'] ?: $p['email'])->take(2)->implode(', ') ?: $thread->account->title;
    $linked = $thread->vehicle ?? $thread->offer;
    $files = $thread->attachments_count ?? ($thread->has_attachments ? 1 : 0);
@endphp
<tr id="thread-{{ $thread->id }}" tabindex="0" class="cursor-pointer {{ $thread->unread_count ? 'font-medium' : '' }}" data-controller="emit" data-action="click->emit#send" data-emit-event-param="letters:open" data-emit-url-param="{{ $base }}/{{ $thread->id }}/window">
    {{-- На телефоне от кого и тема — одной ячейкой в две строки, столбец «От кого» от 640. --}}
    <td class="hidden max-w-[14rem] truncate sm:table-cell">{{ $who }}@if ($thread->messages_count > 1) <span class="nums font-normal text-ink-dim">{{ $thread->messages_count }}</span>@endif</td>
    <td class="grow">
        <span class="block truncate sm:hidden">{{ $who }}@if ($thread->messages_count > 1) <span class="nums font-normal text-ink-dim">{{ $thread->messages_count }}</span>@endif</span>
        <span class="block truncate">{{ $thread->subject ?: '(без темы)' }}@if ($thread->latestMessage?->preview) <span class="font-normal text-ink-dim">{{ $thread->latestMessage->preview }}</span>@endif</span>
    </td>
    <td class="hidden sm:table-cell">@if ($linked)<span class="tag">{{ $thread->vehicle ? $thread->vehicle->titleWithYear() : $thread->offer->title() }}</span>@endif @if (($accounts?->count() ?? 0) > 1 && empty($slug))<span class="tag">{{ $thread->account->title }}</span>@endif</td>
    <td class="w-10 pl-0 text-ink-dim nums">@if ($files)<span class="inline-flex items-center gap-0.5"><x-ui.icon name="clip" class="size-4"/>{{ $files }}</span>@endif</td>
    <td class="num nums text-ink-dim">{{ $thread->last_message_at?->translatedFormat($thread->last_message_at->isToday() ? 'H:i' : 'j M') }}</td>
</tr>
