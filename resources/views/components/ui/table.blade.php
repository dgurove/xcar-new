{{-- Таблица списка (третий вид): плотные строки, по нажатию строка выделяется и
     рядом открывается окошко (peek_controller): на телефоне — снизу, коротко или во
     весь экран (ручка), от 1024 — панелью справа от таблицы. Фрейм peek грузит
     x-ui.peek строки; формы внутри отвечают в окошко (PeekBack), строка таблицы
     обновляется из ответа. Строка — <tr data-peek-url data-href tabindex="0">:
     нажатие — окошко, двойное или ⌘Enter — на страницу, Enter — раскрыть/свернуть,
     ↑/↓ и стрелки у счётчика — по строкам; open — id строки, которую открыть сразу (?peek=). --}}
@props(['head', 'id' => null, 'open' => null])
<div {{ $attributes->merge(['class' => 'table-wrap']) }} data-controller="peek" data-action="keydown->peek#key turbo:before-frame-render->peek#rendering turbo:frame-load->peek#loaded turbo:before-fetch-request->peek#request" @if ($open) data-peek-open-value="{{ $open }}" @endif @if ($id) id="{{ $id }}" @endif>
    <div class="table-box">
        <table class="table">
            <thead>{{ $head }}</thead>
            <tbody data-peek-target="body" data-action="click->peek#tap dblclick->peek#open">{{ $slot }}</tbody>
        </table>
    </div>
    <div class="peek" hidden data-peek-target="panel" role="dialog" aria-label="Транспортное средство" data-action="touchstart->peek#touchStart:passive touchmove->peek#touchMove touchend->peek#touchEnd touchcancel->peek#touchEnd">
        <div class="peek-bar">
            <button type="button" class="peek-handle" data-action="peek#toggle" aria-label="Раскрыть или свернуть"></button>
            <button type="button" class="peek-close" data-action="peek#prev" aria-label="Предыдущая"><x-ui.icon name="chevron-down" class="size-[18px] rotate-180"/></button>
            <button type="button" class="peek-close" data-action="peek#next" aria-label="Следующая"><x-ui.icon name="chevron-down" class="size-[18px]"/></button>
            <span class="peek-count nums" data-peek-target="count"></span>
            <a class="peek-close" href="#" data-peek-target="open" aria-label="Открыть страницу"><x-ui.icon name="expand" class="size-4"/></a>
            <button type="button" class="peek-close" data-action="peek#close" aria-label="Закрыть"><x-ui.icon name="x" class="size-[18px]"/></button>
        </div>
        <turbo-frame id="peek" target="_top" data-peek-target="frame" class="peek-body"><x-ui.skeleton :rows="2"/></turbo-frame>
    </div>
</div>
