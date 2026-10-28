{{-- Кнопки строки `x-ui.share-link` (row, buttons): «Скопировать» кружком — главная на ПК без системного листа
     (`data-copy-fallback`), «Отправить» пилюлей. Кусок, а не компонент: внутри чужого `copy_controller`. --}}
<button type="button" class="btn btn-quiet btn-round !size-8 shrink-0" data-action="copy#copy" data-copy-fallback aria-label="Скопировать"><x-ui.icon name="copy" class="size-4"/></button>
<button type="button" class="btn btn-s btn-accent shrink-0 !h-8 rounded-full !px-3.5" data-action="copy#share" data-copy-target="share">Отправить</button>
