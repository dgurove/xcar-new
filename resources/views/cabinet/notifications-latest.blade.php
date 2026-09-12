<turbo-frame id="{{ $frame }}">
    @if ($items->isEmpty())
        <p class="rounded-(--radius-l) bg-surface-2 px-4 py-8 text-center text-sm text-ink-muted">Пока тихо</p>
    @else
        <ul class="divide-y divide-line">
            @foreach ($items as $item)
                <li>
                    <a href="/lk/uvedomleniya/{{ $item->id }}" class="block py-3 hover:text-accent-text" data-turbo-frame="_top" data-turbo="false">
                        <p class="text-sm font-medium">{{ $item->data['title'] }}@unless ($item->read_at) <span class="ml-1 inline-block size-2 rounded-full bg-accent"></span>@endunless</p>
                        @if (!empty($item->data['text']))<p class="mt-0.5 line-clamp-2 text-sm text-ink-muted">{{ $item->data['text'] }}</p>@endif
                        <p class="mt-1 text-xs text-ink-dim"><time datetime="{{ $item->created_at->toIso8601String() }}" data-controller="timer" data-timer-since-value="{{ $item->created_at->toIso8601String() }}" data-timer-human-value="true">{{ $item->created_at->diffForHumans() }}</time></p>
                    </a>
                </li>
            @endforeach
        </ul>
    @endif
</turbo-frame>
