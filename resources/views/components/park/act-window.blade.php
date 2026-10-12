{{-- Окно действия парковки — одно на страницу (12.10.2026, владелец: «приём в отдельном окне, а не в интерфейсе карточки,
     выдача тоже — разграничить машину и действие»): шторка tall (телефон — экран целиком, от 640 — окно 36rem по центру)
     с фреймом act-frame. Событие act:open с url (`x-park.act-button`) грузит шаг `/cars/{v}/window/{intake|release|report}`
     (`ActWindowController`), url в пропе — открыть сразу (дело с ?pass= — выдача по QR). Шаг done закрывает окно
     (`StreamActions['act-done']`, событие act:close); закрыли руками после отправки — страница перечитывается. --}}
@props(['url' => null])
<div data-controller="sheet window" data-action="act:open@window->window#open act:close@window->window#close" @if ($url) data-window-url-value="{{ $url }}" @endif class="contents">
    <x-ui.sheet id="act" title="Приём" tall class="sheet-act">
        <template data-skeleton><x-ui.skeleton :rows="4"/></template>
        <turbo-frame id="act-frame" class="block" data-window-target="frame" @if ($url) src="{{ $url }}" @endif><x-ui.skeleton :rows="4"/></turbo-frame>
    </x-ui.sheet>
</div>
