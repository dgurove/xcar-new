{{-- Деньги машины одной плашкой. Сверху главное число этапа: до продажи — сколько вложено (сотруднику) или
     сколько потрачено (менеджеру: закупочной на сайте нет, «отдали за» и «вложено» он не видит), после продажи —
     прибыль (нам) или цена (ему), со счётом — сколько осталось отдать или ждём от покупателя, затем выплата
     менеджеру, после расчёта — цена продажи. Ниже строки, из которых оно сложилось. Вознаграждение менеджер
     видит только со счётом, как у сделок. --}}
@php
    use App\Support\Money;
    $unpaid = $current && $current->remaining() > 0;
    $claimed = $unpaid ? $current->claimed() : 0;
    $buyerPays = $car->invoice_to === 'buyer';
    [$label, $value, $tone] = match (true) {
        ! $car->isSold() => $staff ? ['Вложено в ТС', $s['invested'], ''] : ['Расходы', $s['manager_costs'] + $s['our_costs'], ''],
        $unpaid && $current->isOwed() => [$staff ? 'Должны менеджеру' : 'Вам к выплате', $current->remaining(), 'text-accent-text'],
        $unpaid && $buyerPays => ['Покупатель платит', $current->remaining(), $current->isOverdue() ? 'text-danger' : ''],
        $unpaid => [$staff ? 'Менеджер должен нам' : 'Отдать нам', $current->remaining(), $current->isOverdue() ? 'text-danger' : ''],
        $car->state === \App\Garage\CarState::Sold && $staff => ['Прибыль', $s['profit'], $s['profit'] < 0 ? 'text-danger' : ''],
        default => ['Продана за', $car->sold_price, ''],
    };
    $row = 'row justify-between';
    // Состояние выплаты менеджеру — словом выплаты, а не счёта («Выставлен» про наш долг ему не говорит ничего).
    $payoutWord = fn ($i) => $i->state === \App\Billing\InvoiceState::Paid ? 'выплачено' : 'ждёт выплаты'.($i->remaining() > 0 ? ' до '.$i->due_at->translatedFormat('j M') : '');
@endphp
<div class="list">
    <div class="row flex-col items-start gap-1 py-4">
        <span class="text-sm text-ink-muted">{{ $label }}</span>
        <span class="nums text-[32px] leading-none font-bold {{ $tone }}">{{ Money::exact($value) }}</span>
        @if ($claimed > 0)<span class="mt-1 text-sm text-urgent">Сообщили об оплате {{ Money::exact($claimed) }}, ждём подтверждения</span>@endif
    </div>
    @if ($unpaid && ! $current->isOwed())
        <x-billing.pay-status compact :invoice="$current" :staff="$staff" :create="$staff ? null : '/garage/cars/'.$car->offer->number.'/links'" :cancel="$staff ? null : '/garage/cars/'.$car->offer->number.'/links'"/>
    @endif

    @if ($car->isSold() && $label !== 'Продана за')
        <div class="{{ $row }}"><span>Продана за</span><span class="nums">{{ Money::rub($car->sold_price) }}</span></div>
    @endif
    @if ($staff && $car->cost !== null && ! $car->isSold())
        <div class="{{ $row }}"><span>Отдали за</span><span class="nums">{{ Money::rub($car->cost) }}</span></div>
    @endif
    @if (! $car->isSold())
        {{-- Строки складываются в главное число: платили мы — отдельной строкой, и менеджеру тоже. У менеджера
             главное число и есть расходы — без наших строка повторила бы его. --}}
        @if ($staff || $s['our_costs'] > 0)
            <div class="{{ $row }}"><span>{{ $s['our_costs'] > 0 ? ($staff ? 'Расходы менеджера' : 'Ваши расходы') : 'Расходы' }}</span><span class="nums">{{ Money::exact($s['manager_costs']) }}</span></div>
        @endif
        @if ($s['our_costs'] > 0)<div class="{{ $row }}"><span>{{ $staff ? 'Наши расходы' : 'Платили мы' }}</span><span class="nums">{{ Money::exact($s['our_costs']) }}</span></div>@endif
    @elseif ($staff)
        <div class="{{ $row }}"><span>Вложено</span><span class="nums">{{ Money::exact($s['invested']) }}</span></div>
    @else
        <div class="{{ $row }}"><span>Ваши расходы</span><span class="nums">{{ Money::exact($s['manager_costs']) }}</span></div>
    @endif
    @if ($car->isSold() && $invoice && $car->manager)
        <div class="{{ $row }}"><span>{{ $staff ? 'Вознаграждение менеджеру' : 'Ваше вознаграждение' }}</span><span class="nums">{{ Money::rub($s['fee']) }}</span></div>
    @endif
    @if ($staff && $car->isSold() && $label !== 'Прибыль')
        <div class="{{ $row }}"><span>Нам остаётся</span><span class="nums {{ $s['ours'] < 0 ? 'text-danger' : '' }}">{{ Money::exact($s['ours']) }}</span></div>
    @endif
    @if ($car->buyer_name || $car->buyer_phone)
        <div class="{{ $row }}"><span>Покупатель</span><span class="min-w-0 truncate text-right">{{ $car->buyer_name }}@if ($car->buyer_phone) <a href="tel:{{ $car->buyer_phone }}" class="text-accent-text nums">{{ $car->buyer_phone }}</a>@endif</span></div>
    @endif
    {{-- Выплата, что уже крупно сверху, второй строкой не повторяется. --}}
    @if ($invoice && ! ($invoice->isOwed() && $unpaid && $invoice->is($current)))
        @php $pdf = $invoice->getFirstMedia('file'); @endphp
        <{{ $pdf ? 'a' : 'div' }} @if ($pdf) href="/garage/cars/{{ $car->offer->number }}/invoice/pdf" data-doc="pdf" data-doc-name="Счёт {{ $invoice?->label() }}" @endif class="{{ $row }}">
            <span class="min-w-0">
                <span class="block">{{ $invoice->isOwed() ? ($staff ? 'Выплата менеджеру' : 'Выплата вам') : 'Счёт '.$invoice->label() }}@if ($buyerPays) <span class="text-ink-muted">{{ $invoice->party?->name }}</span>@endif</span>
                <span class="row-sub">@if ($invoice->isOwed()){{ $payoutWord($invoice) }}@else{{ $invoice->state->label() }}@if ($invoice->remaining() > 0), до {{ $invoice->due_at->translatedFormat('j M') }}@endif @endif</span>
            </span>
            @if ($pdf)<span class="flex shrink-0 items-center gap-1 text-accent-text"><x-ui.icon name="file" class="size-4"/>PDF</span>@endif
        </{{ $pdf ? 'a' : 'div' }}>
    @endif
    @if ($car->payoutInvoice && ! ($unpaid && $car->payoutInvoice->is($current)))
        <div class="{{ $row }}">
            <span class="min-w-0"><span class="block">{{ $staff ? 'Выплата менеджеру' : 'Выплата вам' }}</span><span class="row-sub">{{ $payoutWord($car->payoutInvoice) }}</span></span>
            <span class="nums">{{ Money::exact($car->payoutInvoice->total) }}</span>
        </div>
    @endif
</div>
