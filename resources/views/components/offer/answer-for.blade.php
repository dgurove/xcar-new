{{-- Ответ за менеджера (он сказал устно) — две пилюли с переспросом, одна разметка у контрпредложения (`x-offer.bid`) и новой
     цены (`x-offer.reprice-asks`), 28.10.2026. `action` получает `agree=1|0`; `agreeText` / `declineText` — что будет. --}}
@props(['action', 'agreeAsk', 'agreeText' => null, 'declineAsk', 'declineText' => null])
<span {{ $attributes->class('flex shrink-0 items-center gap-2') }}>
    <form method="post" action="{{ $action }}" class="contents" data-turbo-confirm="{{ $agreeAsk }}" @if ($agreeText) data-turbo-confirm-text="{{ $agreeText }}" @endif data-turbo-confirm-label="Согласен">
        @csrf<input type="hidden" name="agree" value="1"><button type="submit" class="btn btn-s btn-quiet shrink-0 !h-8 rounded-full !px-3.5">Согласен</button>
    </form>
    <form method="post" action="{{ $action }}" class="contents" data-turbo-confirm="{{ $declineAsk }}" @if ($declineText) data-turbo-confirm-text="{{ $declineText }}" @endif data-turbo-confirm-label="Отказался">
        @csrf<input type="hidden" name="agree" value="0"><button type="submit" class="btn btn-s btn-quiet shrink-0 !h-8 rounded-full !px-3.5">Отказался</button>
    </form>
</span>
