<x-ui.shell title="Ящики" narrow>
    <div class="flex flex-col gap-2">
        @foreach ($accounts as $account)
            <a href="/nastroyki/yashchiki/{{ $account->slug }}" class="row">
                <div class="min-w-0 flex-1">
                    <div class="flex items-center gap-2"><span class="font-medium">{{ $account->title }}</span><span class="text-sm text-ink-muted">{{ $account->email }}</span>@unless ($account->is_active)<span class="chip bg-closed-soft text-closed">выключен</span>@endunless</div>
                    <div class="text-sm text-ink-muted">
                        {{ $account->scope->label() }} · писем {{ $account->messages_count }}
                        @if ($account->last_error)· <span class="text-danger">{{ \Illuminate\Support\Str::limit($account->last_error, 60) }}</span>
                        @elseif ($account->synced_at)· проверен {{ $account->synced_at->diffForHumans() }}@endif
                    </div>
                </div>
                <x-ui.icon name="chevron-right" class="size-5 text-ink-dim"/>
            </a>
        @endforeach
        <a href="/nastroyki/yashchiki/novyy" class="btn btn-quiet self-start"><x-ui.icon name="plus" class="size-5"/> Ящик</a>
    </div>
</x-ui.shell>
