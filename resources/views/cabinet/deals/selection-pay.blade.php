{{-- Оплата подбора (06.10.2026, владелец: «убрать "Платит вы", "Ссылка на оплату" и саму ссылку, просто кнопка» —
     «К оплате 80 000 ₽» со значками СБП, SberPay и карты): одна кнопка, в шторке `x-billing.pay-sheet` — кто платит (смена —
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
        <x-ui.button type="button" block data-action="sheet#open">
            К оплате <span class="nums">{{ \App\Support\Money::rub($left) }}</span>
            <span class="pay-marks" aria-hidden="true"><x-ui.pay-mark name="sbp"/><x-ui.pay-mark name="sberpay"/><x-ui.pay-mark name="card"/></span>
        </x-ui.button>
        <x-billing.pay-sheet id="pay-selection" :invoices="collect([$i])" :action="'/account/money/deals/'.$deal->id.'/pay'" pdf="/account/invoices/{id}/pdf"
            :buyers="$buyers" :open="($errors->any() && old('way')) || ($link && session('open-link') === $link->id)"/>
    </div>
@endif
