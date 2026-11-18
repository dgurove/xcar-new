{{-- Последние уведомления в шторке колокольчика (18.11.2026): строками `notice-line`, группами по дням. --}}
<turbo-frame id="{{ $frame }}">
    @if ($items->isEmpty())
        <p class="rounded-(--radius-l) bg-surface-2 px-4 py-8 text-center text-sm text-ink-muted">Пока тихо</p>
    @else
        @foreach ($items->groupBy(fn ($i) => \App\Notifications\NoticeFaces::day($i->created_at)) as $day => $group)
            <p class="notice-day">{{ $day }}</p>
            <ul class="divide-y divide-line">
                @foreach ($group as $item)
                    @php $href = \App\Notifications\NoticeLink::for($item->data); $foreign = str_starts_with($href, 'http') || str_starts_with($href, '/admin'); @endphp
                    <li>
                        <a href="{{ $href }}" class="notice-mini" data-turbo-frame="_top" data-notice="{{ $item->id }}" @if ($foreign) data-turbo="false" @endif>
                            @include('cabinet.notice-line', ['item' => $item, 'mini' => true, 'alarm' => false])
                        </a>
                    </li>
                @endforeach
            </ul>
        @endforeach
    @endif
</turbo-frame>
