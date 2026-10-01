{{-- Значение поля в «✨ Распознать»: госномер — табличкой, VIN, год и деньги — цифрами. --}}
@if ($field === 'plate')<x-ui.plate :value="$text"/>@else<span class="{{ in_array($field, ['vin', 'value', 'year'], true) ? 'nums' : '' }}">{{ $text }}</span>@endif
