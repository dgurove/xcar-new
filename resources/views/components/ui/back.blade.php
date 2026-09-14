{{-- «‹» у заголовка вложенного экрана на десктопе (на телефоне — капсула в шапке).
     В потоке перед h1; при полях по бокам контейнера — в левом поле (.back-title). --}}
@props(['back'])
<a href="{{ $back[1] }}" class="back-title btn btn-s btn-quiet btn-round hidden shrink-0 md:inline-flex" aria-label="Назад" title="Назад" data-controller="back" data-action="back#go" data-turbo-action="replace">
    <x-ui.icon name="chevron-left" class="-ml-px size-5"/>
</a>
