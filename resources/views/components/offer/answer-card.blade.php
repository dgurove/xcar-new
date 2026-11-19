{{-- Вопрос менеджеру «Согласен / Отказаться» — одна карточка (28.10.2026, владелец: «красивые единые модули»):
     контрпредложение (`x-offer.counter-card`) и новая цена (`x-offer.reprice-ask`). Подпись лаймом, сумма крупно, под ней
     «было …», цитата админа, срок ответа; «Согласен» — главная, отказ — тихой кнопкой, оба с переспросом. Формы шлют
     `action` с `agree=1|0` (или `do` = `agreeDo` / `declineDo`, ответ на контрпредложение по переоценке); ошибка — `error`
     (ключ валидации) под кнопками. --}}
@props(['caption', 'sum', 'sub' => null, 'quote' => null, 'due' => null, 'action', 'agree' => 'Согласен', 'decline' => 'Отказаться',
    'agreeAsk', 'agreeText' => null, 'declineAsk', 'declineText' => null, 'error' => null, 'agreeDo' => null, 'declineDo' => null])
<div {{ $attributes->class('counter-card') }}>
    <div class="text-sm text-accent-text">{{ $caption }}</div>
    <div class="nums mt-1 text-[32px] font-bold leading-tight">{{ \App\Support\Money::rub((int) $sum) }}</div>
    @if ($sub)<div class="nums mt-1 text-sm text-ink-muted">{{ $sub }}</div>@endif
    @if ($quote)<p class="mt-2 text-sm">«{{ $quote }}»</p>@endif
    @if ($due)<p class="mt-2 text-sm">Ответьте до <span class="nums">{{ \App\Support\Ago::html($due->setTimezone('Europe/Moscow'), true, words: false) }}</span></p>@endif
    <div class="mt-4 flex flex-col gap-2">
        {{-- Вопрос — «Вы уверены?» (19.11.2026, `Offers\Warnings`: что делаем, видно по кнопке), ниже — что будет. --}}
        <form method="post" action="{{ $action }}" data-turbo-confirm="{{ \App\Offers\Warnings::TITLE }}" @if ($agreeText ?? $agreeAsk) data-turbo-confirm-text="{{ $agreeText ?? $agreeAsk }}" @endif data-turbo-confirm-label="Да, {{ mb_lcfirst($agree) }}" data-turbo-confirm-cancel="{{ \App\Offers\Warnings::CANCEL }}">
            @csrf @if ($agreeDo)<input type="hidden" name="do" value="{{ $agreeDo }}">@else<input type="hidden" name="agree" value="1">@endif
            <button type="submit" class="btn btn-accent w-full nums">{{ $agree }}</button>
        </form>
        {{-- remind — «Напомнить через…» рядом с отказом (контрпредложение, 19.11.2026): ряд как «Я передумал | Напомнить». --}}
        @isset($remind)<div class="flex gap-2">@endisset
        <form method="post" action="{{ $action }}" data-turbo-confirm="{{ \App\Offers\Warnings::TITLE }}" @if ($declineText ?? $declineAsk) data-turbo-confirm-text="{{ $declineText ?? $declineAsk }}" @endif data-turbo-confirm-label="Да, {{ mb_lcfirst($decline) }}" data-turbo-confirm-cancel="{{ \App\Offers\Warnings::CANCEL }}">
            @csrf @if ($declineDo)<input type="hidden" name="do" value="{{ $declineDo }}">@else<input type="hidden" name="agree" value="0">@endif
            <button type="submit" @class(["btn btn-quiet", "w-full" => ! isset($remind), "shrink-0 !px-4" => isset($remind)]) data-turbo-confirm-danger>{{ $decline }}</button>
        </form>
        @isset($remind){{ $remind }}</div>@endisset
    </div>
    @if ($error)@error($error)<p class="mt-2 text-sm text-danger">{{ $message }}</p>@enderror @endif
</div>
