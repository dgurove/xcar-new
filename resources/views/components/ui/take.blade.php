{{-- Значение из нового письма цепочки под полем (x-ui.field / x-ui.vin с take): нажал — встало в поле. --}}
@props(['name', 'take'])
<button type="button" class="field-take nums" data-action="take#take" data-take-name-param="{{ $name }}" data-take-value-param="{{ $take[1] }}"><span class="opacity-70">В новом письме</span>{{ $take[0] }}</button>
