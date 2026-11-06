{{-- «На почту» под ссылкой для нового пароля (06.11.2026): письмом с no-reply тому, кому её выдали. Почты нет — кнопки нет. --}}
@props(['user', 'url'])
@if ($user->email)
    <form method="post" action="{{ \App\Support\Surface::current() === \App\Support\Surface::Crm ? '/settings' : '' }}/people/{{ $user->id }}/password-link-mail" {{ $attributes }}>
        @csrf<input type="hidden" name="url" value="{{ $url }}">
        <button type="submit" class="btn btn-quiet w-full"><x-ui.icon name="mail" class="size-5"/>На почту {{ $user->email }}</button>
    </form>
@endif
