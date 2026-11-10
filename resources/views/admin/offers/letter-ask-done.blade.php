{{-- «Без письма» из вопроса перед черновиком (`RouteController::letterSkip`): окно закрывается, тост, страница под ним
     перечитывается — шаг уже «ждём поставщика». --}}
<turbo-frame id="letters-frame" target="_top">
    <turbo-stream action="letters-close"></turbo-stream>
    <turbo-stream action="toast" data-message="{{ $toast }}" data-kind=""></turbo-stream>
    <turbo-stream action="refresh"></turbo-stream>
</turbo-frame>
