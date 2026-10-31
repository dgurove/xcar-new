{{-- Редактор схемы парковки (`yard_editor_controller`): ряды тянут пальцем или мышью, ручки — число мест и длина места,
     нажатие на место выбранного ряда — убрать или вернуть его. Выбранный ряд — плашкой в одну строку внизу холста: «Ряд A,
     22 места» открывает шторку с полями, рядом повернуть, копия, убрать. «Сохранить» — в ряду инструментов, только когда схему
     тронули (внизу экрана она закрывала плашку ряда); сохраняется целиком (`SavePlan`). taken — занятые места (место → марка и тип), их не убрать. --}}
<x-ui.shell :title="'Схема «'.$yard->name.'»'" :back="['Схема', '/yards/'.$yard->id.'/plan']">
    <div class="plan-page" data-controller="yard-editor sheet" data-yard-editor-rows-value="{{ json_encode($rows) }}" data-yard-editor-taken-value="{{ json_encode($taken, JSON_FORCE_OBJECT) }}"
         data-yard-editor-rev-value="{{ $rev }}" data-yard-editor-url-value="/yards/{{ $yard->id }}/plan" data-yard-editor-sprite-value="{{ \App\Support\Icons::url() }}">
        <div class="plan-tools">
            <button type="button" class="btn btn-s btn-quiet shrink-0 rounded-full" data-action="yard-editor#add"><x-ui.icon name="plus" class="size-4"/>Ряд</button>
            <button type="button" class="btn btn-s btn-quiet btn-round shrink-0" data-yard-editor-target="undo" data-action="yard-editor#undo" aria-label="Отменить" disabled><x-ui.icon name="undo" class="size-4"/></button>
            <button type="button" class="btn btn-s btn-quiet btn-round shrink-0" data-yard-editor-target="redo" data-action="yard-editor#redo" aria-label="Повторить" disabled><x-ui.icon name="undo" class="size-4 -scale-x-100"/></button>
            <button type="button" class="btn btn-s btn-accent ml-auto shrink-0 rounded-full" data-yard-editor-target="save" data-action="yard-editor#save" hidden>Сохранить</button>
        </div>
        <div class="plan-stage">
            <svg class="plan plan-edit" xmlns="http://www.w3.org/2000/svg" viewBox="-5 -5 40 30" data-box="-5 -5 40 30" preserveAspectRatio="xMidYMid meet" data-yard-editor-target="svg"></svg>
            @include('park.yards.plan-zoom', ['controller' => 'yard-editor'])
            <div class="plan-empty" data-yard-editor-target="empty" hidden>
                <button type="button" class="btn btn-accent rounded-full" data-action="yard-editor#add"><x-ui.icon name="plus" class="size-5"/>Первый ряд</button>
            </div>
            <div class="plan-bar" data-yard-editor-target="panel" hidden>
                <button type="button" class="plan-bar-main" data-action="sheet#open">
                    <span class="min-w-0 truncate font-medium" data-yard-editor-target="title"></span>
                    <x-ui.icon name="chevron-down" class="size-4 shrink-0 text-ink-dim"/>
                </button>
                <button type="button" class="btn btn-s btn-quiet btn-round shrink-0" data-action="yard-editor#turn" aria-label="Повернуть ряд"><x-ui.icon name="rotate" class="size-4"/></button>
                <button type="button" class="btn btn-s btn-quiet btn-round shrink-0" data-action="yard-editor#duplicate" aria-label="Копия ряда"><x-ui.icon name="copy" class="size-4"/></button>
                <button type="button" class="btn btn-s btn-quiet btn-round shrink-0 text-danger" data-yard-editor-target="remove" data-action="yard-editor#remove" aria-label="Убрать ряд"><x-ui.icon name="trash" class="size-4"/></button>
            </div>
        </div>
        <x-ui.sheet id="plan-row" title="Ряд">
            <div class="fields">
                <x-ui.field name="row_name" label="Буква" autocapitalize="characters" autocomplete="off" maxlength="3" data-yard-editor-target="name" data-field="name" data-action="change->yard-editor#field"/>
                <x-ui.field name="row_from" label="С номера" inputmode="numeric" autocomplete="off" data-yard-editor-target="from" data-field="from" data-action="change->yard-editor#field"/>
                <x-ui.field name="row_n" label="Мест" inputmode="numeric" autocomplete="off" data-yard-editor-target="n" data-field="n" data-action="change->yard-editor#field"/>
                <x-ui.field name="row_w" label="Ширина, м" inputmode="decimal" autocomplete="off" data-yard-editor-target="w" data-field="w" data-action="change->yard-editor#field"/>
                <x-ui.field name="row_l" label="Длина, м" inputmode="decimal" autocomplete="off" data-yard-editor-target="l" data-field="l" data-action="change->yard-editor#field"/>
            </div>
            <div class="mt-4"><x-ui.check name="row_rev" data-yard-editor-target="rev" data-action="change->yard-editor#reverse">Нумерация с другого конца</x-ui.check></div>
        </x-ui.sheet>
    </div>
</x-ui.shell>
