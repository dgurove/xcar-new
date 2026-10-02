{{-- Шторка документов — одна на страницу (x-ui.shell), рисует docs_controller: на телефоне лист снизу, от 1024 —
     панель справа. Вкладки — документы страницы, справа номер страницы, ✨ (открытый скан или фото из письма, когда
     на странице есть что заполнять — x-mail.scan-button), поворот, скачать, закрыть. --}}
<div class="docs" hidden data-controller="docs" role="dialog" aria-label="Документы" data-action="keydown.esc@window->docs#escape">
    <div class="docs-edge" data-action="pointerdown->docs#resizeStart" aria-hidden="true"></div>
    <div class="docs-bar" data-action="pointerdown->docs#dragStart">
        <span class="docs-handle" aria-hidden="true"></span>
        <div class="docs-tabs" data-docs-target="tabs"></div>
        <span class="docs-count nums" data-docs-target="count"></span>
        <button type="button" class="peek-close scan-spark" data-docs-target="scan" data-action="docs#scan" aria-label="Из документов" title="Из документов" hidden><x-ui.spark class="size-[18px]"/></button>
        <button type="button" class="peek-close" data-docs-target="rotate" data-action="docs#rotate" aria-label="Повернуть"><x-ui.icon name="rotate" class="size-[18px]"/></button>
        <a href="#" class="peek-close" data-docs-target="download" download aria-label="Скачать" data-controller="file" data-action="file#share"><x-ui.icon name="download" class="size-[18px]"/></a>
        <button type="button" class="peek-close" data-action="docs#close" aria-label="Закрыть"><x-ui.icon name="x" class="size-[18px]"/></button>
    </div>
    <div class="docs-body" data-docs-target="body"></div>
</div>
