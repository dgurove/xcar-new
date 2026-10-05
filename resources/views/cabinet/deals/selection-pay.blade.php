{{-- Оплата подбора XCar — что и сколько заплатить, словами и тут же (06.10.2026, владелец: «в блоке с деньгами не
     очевидно, что он должен что-то оплатить, ссылку сразу, а не в отдельном окне»): заголовок «Оплатите XCar за подбор»,
     сумма крупно и когда (после договора с покупателем — срок начнёт шаг оплаты, `StartSelectionDue`), кто платит
     строкой со сменой плательщика, сама ссылка адресом с «Скопировать» и «Отправить», «по реквизитам или наличными» —
     шторкой. Одно место на экране: на шаге оплаты — в задаче, иначе — в «Расчёте». Оплачено — одной строкой. --}}
@php
    use App\Support\Money;
    use App\Billing\InvoiceState;
    use App\Billing\Acquiring\PayerKind;
    $i = $invoice;
    $me = auth()->user();
    $mine = $deal->buyer_id === $me->id;
    $link = $i->state === InvoiceState::Issued ? $i->openLink() : null;
    $left = round($i->remaining() - $i->claimed(), 2);
    $self = $link && ($link->payer_kind === PayerKind::Self ? in_array($me->id, [$link->created_by, $link->payer_user_id], true)
        : ($link->payer_kind === PayerKind::Other && $me->party_id && $i->party_id === $me->party_id && in_array($link->payer_name, [null, '', $i->party?->name], true)));
    $payerName = $link ? ($self ? 'вы' : ($link->payer_name ?: $i->party?->name)) : null;
    $current = $link && $link->payer_kind === PayerKind::Buyer && $link->payer_user_id ? (string) $link->payer_user_id : 'self';
    $buyers = $mine ? $me->buyers()->with(\App\Users\User::withAvatar())->orderBy('name')->get() : collect();
@endphp
@if ($i->state === InvoiceState::Paid)
    <div class="list {{ $class ?? 'mt-4' }}">
        <div class="row"><span class="min-w-0 flex-1"><span class="block">XCar за подбор</span><span class="row-sub text-open">оплачено</span></span><span class="nums font-semibold">{{ Money::rub($i->total) }}</span></div>
    </div>
@elseif ($i->state === InvoiceState::Issued)
    <section class="{{ $class ?? 'mt-5' }} min-w-0">
        <h3 class="text-lg">Оплатите XCar за подбор</h3>
        <div class="list mt-3">
            <div class="row">
                <span class="min-w-0 flex-1">
                    <span class="nums block text-[32px] font-bold leading-tight">{{ Money::rub($i->remaining()) }}</span>
                    <span class="row-sub !whitespace-normal">@if ($i->claimed() > 0)оплата ждёт подтверждения@elseif (! $i->due_at)после договора с покупателем@else<x-billing.light :invoice="$i"/>@endif</span>
                </span>
            </div>
            @if ($link)
                @if ($mine)
                    <div data-controller="sheet" class="contents">
                        <button type="button" class="row w-full text-left" data-action="sheet#open">
                            <span class="min-w-0 flex-1 text-ink-muted">Платит</span>
                            <span class="min-w-0 truncate">{{ $payerName }}</span>
                            <x-ui.chevron/>
                        </button>
                        <x-ui.sheet :id="'payer-'.$link->id" title="Кто платит" :open="$errors->has('payer_user_id') || $errors->has('name') || $errors->has('email')">
                            <form method="post" action="/account/money/links/{{ $link->id }}/payer" class="flex flex-col gap-5">
                                @csrf @method('put')
                                <x-billing.payer-pick :id="'payer-'.$link->id" :buyers="$buyers" :payer="old('payer', $current)" :cap="false"/>
                                <x-ui.button block>Сохранить</x-ui.button>
                            </form>
                        </x-ui.sheet>
                    </div>
                @else
                    <div class="row"><span class="min-w-0 flex-1 text-ink-muted">Платит</span><span class="min-w-0 truncate">{{ $payerName }}</span></div>
                @endif
            @endif
            <x-billing.pay-status :invoice="$i" inline/>
            @if ($mine && $left > 0)
                <div data-controller="sheet" class="contents">
                    <button type="button" class="row w-full text-left" data-action="sheet#open">
                        <span class="min-w-0 flex-1">Оплачу по реквизитам или наличными</span>
                        <x-ui.chevron/>
                    </button>
                    <x-billing.pay-sheet id="pay-offline" offline :invoices="collect([$i])" :action="'/account/money/deals/'.$deal->id.'/pay'" pdf="/account/invoices/{id}/pdf"
                        :buyers="$buyers" :open="$errors->any() && in_array(old('way'), ['transfer', 'cash'], true)"/>
                </div>
            @endif
        </div>
    </section>
@endif
