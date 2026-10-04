{{-- Просмотрщик документов — один на страницу (x-ui.shell), рисует docs_controller: на телефоне лист снизу, от 1024 —
     панель справа; над модальным окном (окно писем, «Оплатить») — модально поверх него. Полоса: имя документа и под
     ним тип с объёмом, справа ✨ (открытый скан или фото из письма, когда на странице есть что заполнять —
     x-mail.scan-button), поворот (фото и сканы), «Поделиться» на телефоне или «Скачать» и «Печать» на компьютере,
     закрыть. Несколько документов — вкладки под полосой. Номер страницы — капсулой внизу, пока листают. --}}
<dialog class="docs" data-controller="docs" aria-label="Документы"
    data-action="keydown.esc@window->docs#escape cancel->docs#cancel close->docs#closed click->docs#backdrop">
    <div class="docs-edge" data-action="pointerdown->docs#resizeStart" aria-hidden="true"></div>
    <div class="docs-top" data-action="pointerdown->docs#dragStart">
        <span class="docs-handle" aria-hidden="true"></span>
        <div class="docs-bar">
            <div class="docs-title">
                <span class="docs-name" data-docs-target="name"></span>
                <span class="docs-meta" data-docs-target="meta"></span>
            </div>
            <button type="button" class="bar-btn scan-spark" data-docs-target="scan" data-action="docs#scan" aria-label="Из документов" title="Из документов" hidden><x-ui.spark class="size-[18px]"/></button>
            <button type="button" class="bar-btn" data-docs-target="rotate" data-action="docs#rotate" aria-label="Повернуть" title="Повернуть" hidden><x-ui.icon name="rotate" class="size-[18px]"/></button>
            <a href="#" class="bar-btn docs-share" data-docs-target="download" data-doc="off" download aria-label="Скачать" title="Скачать" data-action="pointerdown->docs#warm click->docs#share"><x-ui.icon name="download" class="docs-ico-desk size-[18px]"/><x-ui.icon name="share" class="docs-ico-phone size-[18px]"/></a>
            <button type="button" class="bar-btn" data-docs-target="print" data-action="docs#print" aria-label="Печать" title="Печать" hidden><x-ui.icon name="print" class="size-[18px]"/></button>
            <button type="button" class="bar-btn" data-action="docs#close" aria-label="Закрыть" title="Закрыть"><x-ui.icon name="x" class="size-[18px]"/></button>
        </div>
        <div class="docs-tabs" data-docs-target="tabs" hidden></div>
    </div>
    <div class="docs-body" data-docs-target="body"></div>
    <span class="docs-count" data-docs-target="count" aria-hidden="true"></span>
</dialog>
