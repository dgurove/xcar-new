{{-- «Назад» в шапке чата: к списку, а если пришли из него — шагом истории (`back_controller`). Та же кнопка, что «‹» шапки
     оболочки (`ui/header`: квадрат `header-btn header-sq`), на том же месте — бар чата на телефоне в размерах шапки. --}}
@props(['href'])
<a href="{{ $href }}" class="chat-back header-btn header-sq" aria-label="Назад" title="Назад" data-controller="back" data-action="back#go" data-turbo-action="replace"><x-ui.icon name="chevron-left" class="-ml-px size-5"/></a>
