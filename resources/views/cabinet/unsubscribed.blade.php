{{-- Из письма, без входа: почта выключена; «Вернуть» — той же подписанной ссылкой. --}}
<x-ui.auth title="Письма больше не приходят">
    <p class="mt-3 text-ink-muted">Уведомления останутся в кабинете, на почту {{ $user->email }} писать не будем</p>
    <form method="post" action="{{ url()->full() }}" class="mt-6">
        @csrf
        <x-ui.button block variant="secondary">Вернуть письма</x-ui.button>
    </form>
</x-ui.auth>
