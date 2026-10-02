{{-- Волны «Кому показывать»: список «кто → когда» (строки рисует audience_controller), «+ Группа или менеджер» —
     системный выбор под кнопкой. Один на шторку предложения и карточку вендора. --}}
<div class="list" data-audience-target="list"></div>
<label class="add-select pill pill-plain mt-3">
    <x-ui.icon name="plus" class="size-4"/> Группа или менеджер
    <select data-audience-target="add" data-action="audience#add" aria-label="Добавить группу или менеджера"></select>
</label>
