{{-- Строка, которую на телефоне смахивают влево: под ней кнопки из слота actions
     (Turbo-формы, ответ — turbo-stream с новой строкой). На десктопе хвоста нет. --}}
@props(['id' => null])
<div {{ $attributes->merge(['class' => 'swipe']) }} @if ($id) id="{{ $id }}" @endif data-controller="swipe" data-action="scroll->swipe#scrolled turbo:submit-end->swipe#close">
    <div class="swipe-body">{{ $slot }}</div>
    @if (isset($actions) && !$actions->isEmpty())<div class="swipe-actions">{{ $actions }}</div>@endif
</div>
