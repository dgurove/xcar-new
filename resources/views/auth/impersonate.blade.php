{{-- Вход за человека по ссылке из CRM: кто, кнопка «Войти». Мёртвая ссылка — тот же экран без кнопки. --}}
<x-ui.auth title="Вход в кабинет">
    @if ($as)
        <div class="mt-5 flex items-center gap-3">
            <x-ui.avatar :user="$as->user" :size="44"/>
            <div class="min-w-0">
                <div class="font-medium">{{ $as->user->name }}</div>
                <div class="text-sm text-ink-muted">{{ $as->user->role->label() }}</div>
            </div>
        </div>
        @if ($current)
            <p class="mt-4 text-sm text-ink-muted">Сейчас здесь вы вошли как {{ $current->shortName() }}, этот вход закончится</p>
        @endif
        {{-- Без Turbo: за модератора вход уводит на хост CRM, а редирект на чужой хост fetch не проходит («Нет связи»). --}}
        <form method="post" action="/login/as/{{ $token }}" class="mt-6" data-turbo="false">
            @csrf
            <button type="submit" class="btn btn-accent w-full">Войти как {{ $as->user->shortName() }}</button>
        </form>
    @else
        <p class="mt-4 text-ink-muted">Ссылка уже использована или истекла</p>
        <a href="/login" class="btn btn-accent mt-6 w-full">Войти</a>
    @endif
</x-ui.auth>
