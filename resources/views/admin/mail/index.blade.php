<x-ui.shell title="Почта" :wide="true">
    <div class="mb-4 flex flex-col gap-3">
        <x-ui.switch :items="['/admin/pochta' => 'Письма', '/admin/chaty' => 'Чаты'.($chatsUnread ? ' · '.$chatsUnread : '')]" current="/admin/pochta"/>
        <form method="get" class="flex gap-2" data-controller="autosubmit">
            @if ($slug)<input type="hidden" name="yashchik" value="{{ $slug }}">@endif
            @if ($preset !== 'all')<input type="hidden" name="preset" value="{{ $preset }}">@endif
            <label class="relative flex-1">
                <x-ui.icon name="search" class="pointer-events-none absolute left-3 top-1/2 size-5 -translate-y-1/2 text-ink-dim"/>
                <input type="search" name="q" value="{{ $q }}" placeholder="Тема, отправитель" class="field-input !bg-surface pl-11" enterkeyhint="search">
            </label>
            <a href="{{ $base }}/novoe{{ $slug ? '?yashchik='.$slug : '' }}" class="btn btn-primary shrink-0"><x-ui.icon name="edit" class="size-5"/><span class="hidden sm:inline">Написать</span></a>
        </form>
        <div class="flex items-center gap-2">
            <x-ui.presets :items="\App\Http\Admin\MailController::PRESETS" :current="$preset" :counts="['unread' => $unread]"/>
        </div>
        @if ($accounts->count() > 1)
            <div class="presets">
                <a href="{{ request()->fullUrlWithQuery(['yashchik' => null, 'page' => null]) }}" class="preset" @if (!$slug) aria-current="true" @endif>Все ящики</a>
                @foreach ($accounts as $account)
                    <a href="{{ request()->fullUrlWithQuery(['yashchik' => $account->slug, 'page' => null]) }}" class="preset" @if ($slug === $account->slug) aria-current="true" @endif>{{ $account->title }}</a>
                @endforeach
            </div>
        @endif
    </div>

    @if ($accounts->isEmpty())
        <div class="py-24 text-center text-ink-muted">Ящиков ещё нет — <a href="/admin/yashchiki/novyy" class="text-accent-text">завести</a></div>
    @elseif ($threads->isEmpty())
        <div class="py-24 text-center text-ink-muted">Писем нет</div>
    @else
        <div class="flex flex-col gap-2" id="threads">
            @foreach ($threads as $thread)
                @php $who = collect($thread->counterparts())->map(fn ($p) => $p['name'] ?: $p['email'])->take(3)->implode(', ') ?: $thread->account->title; @endphp
                <a href="{{ $base }}/{{ $thread->id }}" class="row items-start">
                    @if ($thread->unread_count)<span class="mt-2 size-2 shrink-0 rounded-full bg-accent"></span>@endif
                    <div class="min-w-0 flex-1">
                        <div class="flex items-baseline gap-2">
                            <span class="truncate {{ $thread->unread_count ? 'font-medium' : '' }}">{{ $who }}</span>
                            @if ($thread->messages_count > 1)<span class="text-sm text-ink-dim tabular-nums">{{ $thread->messages_count }}</span>@endif
                            <span class="ml-auto shrink-0 text-sm text-ink-dim tabular-nums">{{ $thread->last_message_at?->translatedFormat($thread->last_message_at->isToday() ? 'H:i' : 'j M') }}</span>
                        </div>
                        <div class="truncate {{ $thread->unread_count ? '' : 'text-ink-muted' }}">{{ $thread->subject ?: '(без темы)' }}</div>
                        <div class="mt-1 flex items-center gap-2 text-sm text-ink-muted">
                            @if ($thread->has_attachments)<x-ui.icon name="clip" class="size-4 shrink-0"/>@endif
                            @if ($thread->offer)<span class="chip">№ {{ $thread->offer->number }} · {{ $thread->offer->title() }}</span>@endif
                            @if ($accounts->count() > 1 && !$slug)<span class="chip">{{ $thread->account->title }}</span>@endif
                        </div>
                    </div>
                </a>
            @endforeach
        </div>
        <div class="mt-4">{{ $threads->links() }}</div>
    @endif
</x-ui.shell>
