{{-- Значение поля в «✨ Распознать»: госномер — табличкой, VIN и деньги — цифрами; под ним тусклым — откуда. --}}
<span class="scan-value">
    @if ($field === 'plate')<x-ui.plate :value="$option['text']"/>@else<span class="{{ in_array($field, ['vin', 'value', 'year'], true) ? 'nums' : '' }}">{{ $option['text'] }}</span>@endif
    <span class="scan-from">{{ implode(', ', $option['from']) }}</span>
</span>
