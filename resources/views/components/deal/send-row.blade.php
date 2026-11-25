{{-- «Отправить менеджеру» под счётом или договором сделки в «Продаже» CRM (16.11.2026, владелец: «чтобы сотрудники могли проверить
     всё ли верно, перед тем как отправляется»; вид — вариант Б: кнопка во всю ширину прямо под документом, без полосы между ними).
     Что менеджер ещё не видит, говорит сам документ второй строкой. Отправлено — кнопки нет, у документа серым «у менеджера с …».
     Без менеджера у сделки — нечего отправлять. `pill` — маленькой «Отправить» справа в строке документа (18.11.2026, владелец:
     «кнопку „Отправить счёт“ тоже маленькой справа», как «Выставить» у ссылки). --}}
{{-- then — что отправляем с реквизитами покупателя (`invoice:N`, `contract`): не проверены — «Проверить и …» (`x-deal.buyer-check`, 26.11.2026). --}}
@props(['action', 'deal', 'label' => 'Отправить менеджеру', 'pill' => false, 'then' => null])
@php($check = $then && $deal->contract?->needsBuyerCheck())
@if ($deal->buyer_id && auth()->user()?->canEditDeals())
    @if ($check)
        <div class="send-under"><x-deal.buyer-check :deal="$deal" :then="$then" :label="$label" class="w-full"/></div>
    @elseif ($pill)
        <form method="post" action="{{ $action }}" class="contents">@csrf<button type="submit" class="btn btn-accent btn-xs ml-1">Отправить</button></form>
    @else
    <form method="post" action="{{ $action }}" class="send-under">
        @csrf
        <button type="submit" class="btn btn-accent btn-s w-full gap-2"><x-ui.icon name="send" class="size-4"/>{{ $label }}</button>
    </form>
    @endif
@endif
