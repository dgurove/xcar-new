{{-- Окошко «Привяжите Telegram» в оболочке вошедшего: менеджеру и админу без привязки (User::offerTelegram),
     не на парковке и не в профиле — там это строка. Показали — до завтра не покажем; «Напомнить позже» — через дни. --}}
@php
    $user = auth()->user();
    $show = $user && \App\Support\Surface::current() !== \App\Support\Surface::Park
        && ! request()->is('account', 'settings', 'login*', 'i/*') && $user->offerTelegram();
@endphp
@if ($show)
    <div data-controller="sheet" class="contents">
        <x-telegram.sheet id="telegram-offer" :open="true" later shown/>
    </div>
@endif
