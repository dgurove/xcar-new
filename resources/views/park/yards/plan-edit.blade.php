{{-- Редактор схемы парковки (`yard_editor_controller`): ряды тянут пальцем или мышью, ручки — число мест и длина места,
     нажатие на место выбранного ряда — убрать или вернуть его. Поля выбранного ряда — плашкой внизу холста. Сохраняется
     целиком (`SavePlan`); занятые места — `taken` (место → марка), их не убрать. --}}
<x-ui.shell :title="'Схема «'.$yard->name.'»'" :back="['Схема', '/yards/'.$yard->id.'/plan']">
    <div class="plan-page" data-controller="yard-editor" data-yard-editor-rows-value="{{ json_encode($rows) }}" data-yard-editor-taken-value="{{ json_encode($taken, JSON_FORCE_OBJECT) }}"
         data-yard-editor-rev-value="{{ $rev }}" data-yard-editor-url-value="/yards/{{ $yard->id }}/plan">
        <div class="plan-tools">
            <button type="button" class="btn btn-s btn-quiet shrink-0 rounded-full" data-action="yard-editor#add"><x-ui.icon name="plus" class="size-4"/>Ряд</button>
            <button type="button" class="btn btn-s btn-quiet btn-round shrink-0" data-yard-editor-target="undo" data-action="yard-editor#undo" aria-label="Отменить" disabled><x-ui.icon name="undo" class="size-4"/></button>
            <button type="button" class="btn btn-s btn-quiet btn-round shrink-0" data-yard-editor-target="redo" data-action="yard-editor#redo" aria-label="Повторить" disabled><x-ui.icon name="undo" class="size-4 -scale-x-100"/></button>
            <button type="button" class="btn btn-s btn-accent ml-auto shrink-0 rounded-full" data-yard-editor-target="save" data-action="yard-editor#save" disabled>Сохранить</button>
        </div>
        <div class="plan-stage plan-stage-edit">
            <svg class="plan plan-edit" xmlns="http://www.w3.org/2000/svg" viewBox="-5 -5 40 30" data-box="-5 -5 40 30" preserveAspectRatio="xMidYMid meet" data-yard-editor-target="svg"></svg>
            <div class="plan-zoom">
                <button type="button" class="plan-zoom-btn" data-action="yard-editor#zoomIn" aria-label="Ближе"><x-ui.icon name="plus" class="size-5"/></button>
                <button type="button" class="plan-zoom-btn" data-action="yard-editor#zoomOut" aria-label="Дальше"><span class="plan-minus"></span></button>
                <button type="button" class="plan-zoom-btn" data-action="yard-editor#fit" aria-label="Вся парковка"><x-ui.icon name="expand" class="size-4"/></button>
            </div>
            <div class="plan-empty" data-yard-editor-target="empty" hidden>
                <button type="button" class="btn btn-accent rounded-full" data-action="yard-editor#add"><x-ui.icon name="plus" class="size-5"/>Первый ряд</button>
            </div>
            <div class="plan-panel" data-yard-editor-target="panel" hidden>
                <div class="plan-panel-fields">
                    <label class="plan-panel-field"><span>Ряд</span><input class="field-input field-s" data-yard-editor-target="name" data-field="name" data-action="change->yard-editor#field" autocapitalize="characters" autocomplete="off" maxlength="3"></label>
                    <label class="plan-panel-field"><span>С номера</span><input class="field-input field-s" data-yard-editor-target="from" data-field="from" data-action="change->yard-editor#field" inputmode="numeric" autocomplete="off"></label>
                    <label class="plan-panel-field"><span>Мест</span><input class="field-input field-s" data-yard-editor-target="n" data-field="n" data-action="change->yard-editor#field" inputmode="numeric" autocomplete="off"></label>
                    <label class="plan-panel-field"><span>Ширина</span><input class="field-input field-s" data-yard-editor-target="w" data-field="w" data-action="change->yard-editor#field" inputmode="decimal" autocomplete="off"></label>
                    <label class="plan-panel-field"><span>Длина</span><input class="field-input field-s" data-yard-editor-target="l" data-field="l" data-action="change->yard-editor#field" inputmode="decimal" autocomplete="off"></label>
                </div>
                <div class="plan-panel-acts">
                    <button type="button" class="plan-panel-btn" data-yard-editor-target="dir" data-action="yard-editor#turn"><x-ui.icon name="rotate" class="size-4"/><span data-dir-label>Вправо</span></button>
                    <button type="button" class="plan-panel-btn" data-yard-editor-target="rev" data-action="yard-editor#reverse"><x-ui.icon name="sort" class="size-4"/>С конца</button>
                    <button type="button" class="plan-panel-btn" data-action="yard-editor#duplicate"><x-ui.icon name="copy" class="size-4"/>Копия</button>
                    <button type="button" class="plan-panel-btn text-danger" data-yard-editor-target="remove" data-action="yard-editor#remove"><x-ui.icon name="trash" class="size-4"/>Убрать</button>
                </div>
            </div>
        </div>
    </div>
</x-ui.shell>
