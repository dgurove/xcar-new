{{-- Аватар поставщика в ряду заголовка (08.11.2026: шапки у партнёра нет): меню — кто он и чей, «Профиль», тема, «Выйти». --}}
@php $me = auth()->user(); @endphp
<div class="contents" data-controller="menu">
    <button type="button" class="btn btn-s btn-quiet btn-round shrink-0 !p-0" data-action="menu#toggle" aria-haspopup="menu" aria-controls="partner-me" aria-label="{{ $me->name }}">
        <x-ui.avatar :user="$me" :size="36"/>
    </button>
    <div id="partner-me" class="menu" popover data-menu-target="list" data-align="end" role="menu">
        <div class="menu-group">
            <div class="flex flex-col px-4 pb-2.5 pt-3">
                <span class="font-medium">{{ $me->name }}</span>
                @if ($me->vendor)<span class="text-sm text-ink-muted">{{ $me->vendor->name }}</span>@endif
            </div>
        </div>
        <div class="menu-group">
            <a href="/account" class="menu-item" role="menuitem"><x-ui.icon name="user" class="size-5 text-ink-muted"/>Профиль</a>
            <button type="button" class="menu-item" role="menuitem" data-controller="theme" data-action="theme#toggle"><x-ui.icon name="sun" class="on-light size-5 text-ink-muted"/><x-ui.icon name="moon" class="on-dark size-5 text-ink-muted"/>Тема</button>
            <form method="post" action="/logout" class="contents">@csrf<button type="submit" class="menu-item" role="menuitem"><x-ui.icon name="exit" class="size-5 text-ink-muted"/>Выйти</button></form>
        </div>
    </div>
</div>
