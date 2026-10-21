{{-- «Войти через XCar» из бота (`TelegramConnectController`, 21.10.2026): чей Telegram и к какому аккаунту — и «Подключить». --}}
@php $who = isset($tg) ? trim(($tg['name'] ?? '').($tg['username'] ? ' @'.$tg['username'] : '')) ?: 'Telegram' : null; @endphp
<x-ui.auth :title="($done ?? false) ? 'Telegram подключён' : 'Подключить Telegram'">
    @if ($expired ?? false)
        <p class="mt-4 text-ink-muted">Ссылка устарела, нажмите «Войти через XCar» в боте ещё раз</p>
    @elseif ($done ?? false)
        <p class="mt-4 text-ink-muted">{{ $who }} подключён к аккаунту {{ $user->name }}</p>
        @if ($tg['link'])<a href="{{ $tg['link'] }}" class="btn btn-accent mt-6 w-full">Вернуться в Telegram</a>@endif
    @elseif (! $allowed)
        <p class="mt-4 text-ink-muted">К аккаунту {{ $user->name }} Telegram не подключить</p>
    @else
        <div class="list mt-5">
            <div class="row"><span class="min-w-0 flex-1 text-ink-muted">Telegram</span><span class="truncate">{{ $who }}</span></div>
            <div class="row"><span class="min-w-0 flex-1 text-ink-muted">Аккаунт XCar</span><span class="truncate">{{ $user->name }}</span></div>
        </div>
        <form method="post" action="{{ request()->fullUrl() }}" class="mt-6">
            @csrf
            <button type="submit" class="btn btn-accent w-full">Подключить</button>
        </form>
    @endif
</x-ui.auth>
