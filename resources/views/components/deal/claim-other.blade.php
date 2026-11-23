{{-- «Это за другое» у сообщения менеджера об оплате ООО «ПРАЙМ» (16.11.2026, владелец по 2611134025: чек Альфы на 2,7 млн лёг к
     оплате подбора на 88 190 ₽): чек — на самом деле оплата поставщику. Шторка говорит, что будет, — `SwapPaymentProof::toSupplier`.
     Только где оплата поставщику есть и ждёт чек или подтверждения (`SwapPaymentProof::supplierTarget`). --}}
@props(['payment'])
@php $target = \App\Billing\Actions\SwapPaymentProof::supplierTarget($payment); @endphp
@if ($target)
    <div data-controller="sheet" class="contents">
        <x-ui.button type="button" size="sm" variant="secondary" data-action="sheet#open">Это за другое</x-ui.button>
        <x-ui.sheet :id="'claim-other-'.$payment->id" title="Чек — оплата поставщику?">
            <p class="text-ink-muted">
                @if ($target->done_at)
                    Чек ляжет к платёжному поручению поставщику — уйдёт страховой вместе с ней.
                @else
                    Чек станет платёжным поручением поставщику (PDF), шаг «{{ $target->stage->name }}» пройдёт за менеджера, дальше — «Отправить страховой».
                @endif
                Сообщение об оплате {{ $payment->invoice->seller->config()['name'] ?? 'по счёту' }} на {{ \App\Support\Money::rub($payment->amount) }} снимется, счёт снова ждёт оплату.
            </p>
            <form method="post" action="/work/payments/{{ $payment->id }}/to-supplier">
                @csrf
                <x-ui.sheet-foot>Перенести в оплату поставщику</x-ui.sheet-foot>
            </form>
        </x-ui.sheet>
    </div>
@endif
