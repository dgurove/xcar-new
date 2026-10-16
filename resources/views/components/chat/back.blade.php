{{-- «Назад» в шапке чата: к списку, а если пришли из него — шагом истории (`back_controller`). --}}
@props(['href'])
<a href="{{ $href }}" class="chat-back header-btn" aria-label="Назад" data-controller="back" data-action="back#go" data-turbo-action="replace"><x-ui.icon name="chevron-left" class="size-5"/></a>
