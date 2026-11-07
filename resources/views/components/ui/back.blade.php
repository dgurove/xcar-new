{{-- «‹» у заголовка вложенного экрана на десктопе (на телефоне — капсула в шапке; у партнёра шапки нет — `phone`, и там).
     В потоке перед h1; при полях по бокам контейнера — в левом поле (.back-title). --}}
@props(['back', 'phone' => false])
<a href="{{ $back[1] }}" @class(['back-title btn btn-s btn-quiet btn-round shrink-0', 'hidden md:inline-flex' => ! $phone, 'inline-flex' => $phone]) aria-label="Назад" title="Назад" data-controller="back" data-action="back#go" data-turbo-action="replace">
    <x-ui.icon name="chevron-left" class="-ml-px size-5"/>
</a>
