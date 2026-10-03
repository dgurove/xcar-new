{{-- Итог «Подставить» во фрейм окна «✨ Распознать»: scan_controller по цели done закрывает окно, показывает тост и
     перечитывает то, откуда окно открыли (карточка строки, страницу; под окном писем — когда его закроют). --}}
<turbo-frame id="scan-frame">
    <div data-controller="scan" class="scan">
        <span hidden data-scan-target="done" data-message="{{ $message }}"></span>
        <x-ui.skeleton :rows="2"/>
    </div>
</turbo-frame>
