{{-- Строка таблицы почты: точка непрочитанного, от кого, тема, ТС или предложение, скрепка, когда. Нажатие — окошко со всеми письмами. --}}
@props(['thread', 'base', 'accounts' => null, 'slug' => null])
@php
    $who = collect($thread->counterparts())->map(fn ($p) => $p['name'] ?: $p['email'])->take(2)->implode(', ') ?: $thread->account->title;
    $href = $base.'/'.$thread->id;
    $linked = $thread->vehicle ?? $thread->offer;
@endphp
<tr id="thread-{{ $thread->id }}" data-peek-url="{{ $href }}/peek" data-href="{{ $href }}" tabindex="0" class="{{ $thread->unread_count ? 'font-medium' : '' }}">
    <td class="w-4 pr-0">@if ($thread->unread_count)<span class="dot dot-open"></span>@endif</td>
    {{-- На телефоне от кого и тема — одной ячейкой в две строки, столбец «От кого» от 640. --}}
    <td class="hidden max-w-[14rem] truncate sm:table-cell">{{ $who }}@if ($thread->messages_count > 1) <span class="nums font-normal text-ink-dim">{{ $thread->messages_count }}</span>@endif</td>
    <td class="grow">
        <span class="block truncate sm:hidden">{{ $who }}@if ($thread->messages_count > 1) <span class="nums font-normal text-ink-dim">{{ $thread->messages_count }}</span>@endif</span>
        <span class="block truncate {{ $thread->unread_count ? '' : 'text-ink-muted' }}">{{ $thread->subject ?: '(без темы)' }}</span>
    </td>
    <td class="hidden sm:table-cell">@if ($linked)<span class="tag">{{ $thread->vehicle ? $thread->vehicle->titleWithYear() : $thread->offer->title() }}</span>@endif @if (($accounts?->count() ?? 0) > 1 && empty($slug))<span class="tag">{{ $thread->account->title }}</span>@endif</td>
    <td class="w-6 pl-0 text-ink-dim">@if ($thread->has_attachments)<x-ui.icon name="clip" class="size-4"/>@endif</td>
    <td class="num nums text-ink-dim">{{ $thread->last_message_at?->translatedFormat($thread->last_message_at->isToday() ? 'H:i' : 'j M') }}</td>
</tr>
