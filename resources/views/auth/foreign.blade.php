{{-- Стена чужого хоста для вошедшего: без шапки и разделов — в кабинет на сайт, написать, выйти. --}}
@php
    $site = \App\Support\Surface::Site;
    $title = $surface === \App\Support\Surface::Garage ? 'Сюда только менеджерам' : 'Сюда только сотрудникам';
    $whose = match ($surface) {
        \App\Support\Surface::Park => 'парковка',
        \App\Support\Surface::Garage => 'гараж',
        default => 'администрация',
    };
@endphp
<x-ui.auth :title="$title">
    <p class="mt-4 text-ink-muted">{{ $user->name }}, здесь работает {{ $whose }} XCar. Ваш кабинет на сайте {{ $site->host() }}.</p>
    <div class="mt-6 flex flex-col gap-2">
        <a href="{{ $site->url('/account') }}" class="btn btn-accent w-full"><x-ui.icon name="user" class="size-5"/> В кабинет</a>
        <a href="{{ $site->url('/contacts') }}" class="btn btn-quiet w-full"><x-ui.icon name="mail" class="size-5"/> Написать нам</a>
        <form method="post" action="/logout">@csrf<button type="submit" class="btn btn-ghost w-full">Выйти</button></form>
    </div>
</x-ui.auth>
