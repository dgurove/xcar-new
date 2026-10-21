{{-- «Войти через XCar» из бота (`TelegramConnectController`, 21.10.2026): вошли — «Подключить» ведёт обратно в бот со
     ссылкой привязки своего аккаунта; привязывается Telegram, из которого её откроют. --}}
<x-ui.auth title="Подключить Telegram">
    @if (! $allowed || ! $link)
        <p class="mt-4 text-ink-muted">К аккаунту {{ $user->name }} Telegram не подключить</p>
    @else
        <p class="mt-4 text-ink-muted">Аккаунт {{ $user->name }}. Нажмите «Подключить» и в Telegram — «Запустить»</p>
        <a href="{{ $link }}" class="btn btn-accent mt-6 w-full">Подключить @{{ $bot }}</a>
    @endif
</x-ui.auth>
