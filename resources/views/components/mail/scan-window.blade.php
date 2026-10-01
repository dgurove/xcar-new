{{-- Окно «✨ Распознать» — одно на страницу, как окно писем: шторка tall с фреймом scan-frame. Событие scan:open
     с url (кнопка ✨ в x-mail.chain-head) грузит во фрейм файлы цепочки (ScanController). --}}
<div data-controller="sheet window" data-action="scan:open@window->window#open" class="contents">
    <x-ui.sheet id="scan" title="Распознать" tall>
        <template data-skeleton><x-ui.skeleton :rows="2"/></template>
        <turbo-frame id="scan-frame" refresh="morph" data-window-target="frame"><x-ui.skeleton :rows="2"/></turbo-frame>
    </x-ui.sheet>
</div>
