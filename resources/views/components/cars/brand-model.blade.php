{{-- Марка и модель — два справочника рядом: новая марка сбрасывает модель, модели — только выбранной марки. Сетку даёт
     вмещающий блок. Значения — как есть (old() — у вызывающего). --}}
@props(['brandId' => null, 'brandText' => null, 'modelId' => null, 'modelText' => null, 'required' => false])
<x-ui.combobox name="brand_id" label="Марка" url="/reference/brands" create="/reference/brands" :value="$brandId" :text="$brandText" resets="#cb-model_id" :required="$required"/>
<x-ui.combobox name="model_id" label="Модель" url="/reference/models" create="/reference/models" depends="#cb-brand_id [name=brand_id]" :value="$modelId" :text="$modelText" :required="$required"/>
