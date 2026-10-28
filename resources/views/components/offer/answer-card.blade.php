{{-- Вопрос менеджеру «Согласен / Отказаться» — одна карточка (28.10.2026, владелец: «красивые единые модули»):
     контрпредложение (`x-offer.counter-card`) и новая цена (`x-offer.reprice-ask`). Подпись лаймом, сумма крупно, под ней
     «было …», цитата админа, срок ответа; «Согласен» — главная, отказ — тихой кнопкой, оба с переспросом. Формы шлют
     `action` с `agree=1|0`; ошибка — `error` (ключ валидации) под кнопками. --}}
@props(['caption', 'sum', 'sub' => null, 'quote' => null, 'due' => null, 'action', 'agree' => 'Согласен', 'decline' => 'Отказаться',
    'agreeAsk', 'agreeText' => null, 'declineAsk', 'declineText' => null, 'error' => null])
<div {{ $attributes->class('counter-card') }}>
    <div class="text-sm text-accent-text">{{ $caption }}</div>
    <div class="nums mt-1 text-[32px] font-bold leading-tight">{{ \App\Support\Money::rub((int) $sum) }}</div>
    @if ($sub)<div class="nums mt-1 text-sm text-ink-muted">{{ $sub }}</div>@endif
    @if ($quote)<p class="mt-2 text-sm">«{{ $quote }}»</p>@endif
    @if ($due)<p class="mt-2 text-sm">Ответьте до <span class="nums">{{ $due->setTimezone('Europe/Moscow')->translatedFormat('j M, H:i') }}</span></p>@endif
    <div class="mt-4 flex flex-col gap-2">
        <form method="post" action="{{ $action }}" data-turbo-confirm="{{ $agreeAsk }}" @if ($agreeText) data-turbo-confirm-text="{{ $agreeText }}" @endif data-turbo-confirm-label="Согласен">
            @csrf<input type="hidden" name="agree" value="1">
            <button type="submit" class="btn btn-accent w-full nums">{{ $agree }}</button>
        </form>
        <form method="post" action="{{ $action }}" data-turbo-confirm="{{ $declineAsk }}" @if ($declineText) data-turbo-confirm-text="{{ $declineText }}" @endif data-turbo-confirm-label="{{ $decline }}">
            @csrf<input type="hidden" name="agree" value="0">
            <button type="submit" class="btn btn-quiet w-full">{{ $decline }}</button>
        </form>
    </div>
    @if ($error)@error($error)<p class="mt-2 text-sm text-danger">{{ $message }}</p>@enderror @endif
</div>
