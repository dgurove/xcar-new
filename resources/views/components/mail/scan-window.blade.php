{{-- Окно «✨ Распознать» — одно на приложение (x-ui.shell у сотрудников парковки и CRM), как шторка документов:
     шторка tall с фреймом scan-frame поверх того, откуда её открыли. Событие scan:open с url (x-mail.scan-button)
     грузит во фрейм файлы цепочки, ТС или предложения (ScanController); страницу после «Подставить» перечитывает
     scan_controller, не закрытие окна. --}}
<div data-controller="sheet window" data-window-reload-value="false" data-action="scan:open@window->window#open" class="contents">
    <x-ui.sheet id="scan" title="Распознать" tall>
        <template data-skeleton><x-ui.skeleton :rows="2"/></template>
        <turbo-frame id="scan-frame" refresh="morph" data-window-target="frame"><x-ui.skeleton :rows="2"/></turbo-frame>
    </x-ui.sheet>
</div>
