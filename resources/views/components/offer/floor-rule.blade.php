{{-- Строка диапазона наценки (x-offer.floor-rules): граница, наценка и её вид. Последняя — «дальше», без границы. --}}
<div class="floor-rule" data-floor-rules-target="row">
    <span class="floor-rule-edge">
        <span class="floor-rule-word" data-role="word">{{ $last ? 'дальше' : 'до' }}</span>
        <input type="text" inputmode="decimal" autocomplete="off" class="field-input nums" value="{{ $below }}" aria-label="Оценочная до, ₽"
            data-role="below" data-controller="digits" data-action="input->digits#format input->floor-rules#change" @if ($last) hidden @endif>
    </span>
    <span class="floor-rule-plus">+</span>
    <input type="text" inputmode="decimal" autocomplete="off" class="field-input nums floor-rule-amount" value="{{ $amount }}" aria-label="Наценка"
        data-role="amount" data-action="input->floor-rules#change blur->floor-rules#tidy">
    <select class="field-input floor-rule-unit" data-role="unit" data-action="change->floor-rules#change" aria-label="Рубли или проценты">
        <option value="rub" @selected($unit === 'rub')>₽</option>
        <option value="pct" @selected($unit === 'pct')>%</option>
    </select>
    <button type="button" class="btn btn-s btn-quiet btn-round" data-action="floor-rules#remove" aria-label="Убрать диапазон"><x-ui.icon name="x" class="size-4"/></button>
</div>
