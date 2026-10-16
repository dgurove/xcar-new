{{-- Сумма крупно по центру — поле оплаты и ссылки на оплату: разряды сами (digits), ₽ ставит вмещающий label. Остальные
     атрибуты (цель контроллера, autofocus) — в конец поля. --}}
@props(['value' => null, 'autofocus' => false])
<input name="amount" inputmode="decimal" autocomplete="off" class="pay-amount nums" aria-label="Сумма, ₽" placeholder="0" value="{{ $value }}" data-controller="digits" data-action="input->digits#format" @if ($autofocus) autofocus @endif {{ $attributes }}>
