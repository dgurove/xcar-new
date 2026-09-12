@php $crm = $base !== '/pochta'; @endphp
<x-ui.shell title="Почта" :heading="false" :trail="$crm ? [['Главная', '/'], ['Переписки', '/perepiski'], ['Почта']] : [['Главная', '/'], ['Почта']]">
    <div class="flex flex-wrap items-baseline gap-x-6 gap-y-2">
        @if ($crm)
            <x-ui.section-title level="h1" :count="$threads->total()">Почта</x-ui.section-title>
            <x-ui.section-title href="/perepiski/chaty" :current="false" :count="$chatsUnread ?: null">Чаты</x-ui.section-title>
        @else
            <x-ui.section-title level="h1" :count="$threads->total()">Почта</x-ui.section-title>
        @endif
    </div>

    <x-ui.toolbar class="mt-5" :pills="\App\Http\Admin\MailController::PRESETS" :pill="$preset" pill-param="preset" :counts="['unread' => $unread]" :hidden="['yashchik' => $slug]" name="mail">
        <x-slot:extra>
            <a href="{{ $base }}/novoe{{ $slug ? '?yashchik='.$slug : '' }}" class="btn btn-s btn-accent shrink-0 rounded-full"><x-ui.icon name="edit" class="size-4"/><span class="hidden sm:inline">Написать</span></a>
        </x-slot:extra>
        <x-slot:filters>
            <input name="q" value="{{ $q }}" placeholder="Тема, отправитель" class="field-input field-s">
            @if ($accounts->count() > 1)
                <select name="yashchik" class="field-input field-s" aria-label="Ящик">
                    <option value="">Все ящики</option>
                    @foreach ($accounts as $account)<option value="{{ $account->slug }}" @selected($slug === $account->slug)>{{ $account->title }}</option>@endforeach
                </select>
            @endif
        </x-slot:filters>
    </x-ui.toolbar>

    @if ($accounts->isEmpty())
        <x-ui.empty class="mt-6" href="/nastroyki/yashchiki/novyy" link="Завести ящик">Ящиков ещё нет.</x-ui.empty>
    @elseif ($threads->isEmpty())
        <x-ui.empty class="mt-6">Писем нет.</x-ui.empty>
    @else
        <div class="mt-6 flex flex-col gap-2" id="threads">
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
                            @if ($thread->vehicle)<span class="chip">{{ $thread->vehicle->titleWithYear() }}</span>@endif
                            @if ($accounts->count() > 1 && !$slug)<span class="chip">{{ $thread->account->title }}</span>@endif
                        </div>
                    </div>
                </a>
            @endforeach
        </div>
        <div class="mt-8">{{ $threads->links() }}</div>
    @endif
</x-ui.shell>
