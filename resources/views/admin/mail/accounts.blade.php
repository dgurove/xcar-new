<x-ui.cabinet title="Ящики">
    <div class="list">
        <a href="/settings/mailboxes/new" class="row">
            <span class="flex size-11 shrink-0 items-center justify-center rounded-full bg-accent text-white"><x-ui.icon name="plus" class="size-5"/></span>
            <span class="min-w-0 flex-1 font-medium">Новый ящик</span>
        </a>
        @foreach ($accounts as $account)
            <a href="/settings/mailboxes/{{ $account->slug }}" class="row">
                <div class="min-w-0 flex-1">
                    <div class="flex items-center gap-2"><span class="font-medium">{{ $account->title }}</span><span class="text-sm text-ink-muted">{{ $account->email }}</span>@unless ($account->is_active)<span class="chip bg-closed-soft text-closed">выключен</span>@endunless</div>
                    <div class="mt-1 flex flex-wrap items-center gap-1.5 text-sm text-ink-muted">
                        <span>{{ $account->scope->label() }}</span><span class="nums">писем {{ $account->messages_count }}</span>
                        @if ($account->last_error)<span class="text-danger">{{ \Illuminate\Support\Str::limit($account->last_error, 60) }}</span>
                        @elseif ($account->synced_at)<span>проверен <time datetime="{{ $account->synced_at->toIso8601String() }}" data-controller="timer" data-timer-since-value="{{ $account->synced_at->toIso8601String() }}" data-timer-human-value="true">{{ $account->synced_at->diffForHumans() }}</time></span>@endif
                    </div>
                </div>
                <x-ui.icon name="chevron-right" class="size-5 text-ink-dim"/>
            </a>
        @endforeach
    </div>
</x-ui.cabinet>
