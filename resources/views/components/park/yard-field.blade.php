{{-- Поле «Парковка» в формах дела: выбор — только когда парковок больше одной; у управляющего одной парковки
     (Питер, `Park\City`) выбирать нечего — она подставляется сама скрытым полем, с теми же data-атрибутами для мест. --}}
@props(['yards', 'label' => 'Парковка', 'value' => null, 'placeholder' => null, 'required' => false, 'disabled' => false])
@if (count($yards) === 1)
    <input type="hidden" name="yard_id" value="{{ collect($yards)->keys()->first() }}" @disabled($disabled) {{ $attributes->only(['data-spots-target', 'data-action']) }}>
@else
    <x-ui.field name="yard_id" :label="$label" :options="$yards" :value="$value" :placeholder="$placeholder" :required="$required" :disabled="$disabled" {{ $attributes }}/>
@endif
