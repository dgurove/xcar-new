{{-- Оплата подбора (06.10.2026, владелец: «убрать "Платит вы", "Ссылка на оплату" и саму ссылку, просто кнопка» —
     «К оплате 80 000 ₽», способы оплаты знаками справа от неё): одна кнопка, в шторке `x-billing.pay-sheet` — кто платит (смена —
     новая ссылка, прежняя гаснет), сама ссылка, по счёту или наличными. Одно место на экране: на шаге оплаты — в задаче,
     иначе — в «Расчёте». Оплачено или всё заявлено — кнопки нет, состояние словом в строке «Оплата услуг подбора». --}}
@php
    use App\Billing\InvoiceState;
    $i = $invoice;
    $mine = $deal->buyer_id === auth()->id();
    $left = round($i->remaining() - $i->claimed(), 2);
    $link = $i->openLink();
    $buyers = $mine ? auth()->user()->buyers()->with(\App\Users\User::withAvatar())->orderBy('name')->get() : collect();
@endphp
@if ($mine && $i->state === InvoiceState::Issued && $left > 0)
    <div data-controller="sheet" class="{{ $class ?? 'mt-4' }}">
        {{-- Способы — справа от кнопки, знаками без подписей (06.10.2026, владелец: «не в кнопке, а справа, компактно»). --}}
        <div class="flex items-center gap-4">
            <x-ui.button type="button" class="min-w-0 flex-1" data-action="sheet#open">К оплате <span class="nums">{{ \App\Support\Money::rub($left) }}</span></x-ui.button>
            {{-- Высоты подобраны на глаз: карта в своей сетке 24 занимает 21 × 15 и плотнее, иначе кажется крупнее СБП и Сбера. --}}
            <span class="flex shrink-0 items-center gap-2 text-ink" aria-label="СБП, SberPay, карта">
                <x-ui.pay-mark name="sbp" class="h-4 w-auto"/><x-ui.pay-mark name="sberpay" class="h-4 w-auto"/><x-ui.pay-mark name="card" class="h-[17px] w-auto"/>
            </span>
        </div>
        <x-billing.pay-sheet id="pay-selection" :invoices="collect([$i])" :action="'/account/money/deals/'.$deal->id.'/pay'" pdf="/account/invoices/{id}/pdf"
            :buyers="$buyers" :open="($errors->any() && old('way')) || ($link && session('open-link') === $link->id)"/>
    </div>
@endif
