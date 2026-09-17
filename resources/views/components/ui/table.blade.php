{{-- Таблица списка (третий вид): плотные строки, по нажатию строка выделяется и
     внизу всплывает окошко (peek_controller + x-ui.peek-frame). Строка —
     <tr data-peek-url data-href tabindex="0">: нажатие — окошко, двойное или Enter —
     на страницу; head — слот с <tr> шапки. --}}
@props(['head', 'id' => null])
<div {{ $attributes->merge(['class' => 'table-wrap']) }} data-controller="peek" data-action="keydown->peek#key" @if ($id) id="{{ $id }}" @endif>
    <table class="table">
        <thead>{{ $head }}</thead>
        <tbody data-peek-target="body" data-action="click->peek#tap dblclick->peek#open">{{ $slot }}</tbody>
    </table>
    <div class="peek" hidden data-peek-target="panel" data-turbo-temporary role="dialog" aria-label="Машина" data-action="touchstart->peek#touchStart:passive touchmove->peek#touchMove touchend->peek#touchEnd">
        <button type="button" class="peek-close" data-action="peek#close" aria-label="Закрыть"><x-ui.icon name="x" class="size-[18px]"/></button>
        <turbo-frame id="peek" target="_top" data-peek-target="frame" class="block"><x-ui.skeleton :rows="2"/></turbo-frame>
    </div>
</div>
