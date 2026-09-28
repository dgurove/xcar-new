{{-- Деньги машины одной плашкой. Сверху главное число этапа: в ремонте — сколько вложено, после продажи —
     прибыль (нам) или цена (ему), со счётом — сколько осталось отдать, после расчёта — цена продажи.
     Ниже строки, из которых оно сложилось. Вознаграждение менеджер видит только со счётом, как у сделок. --}}
@php
    use App\Support\Money;
    $unpaid = $invoice && $invoice->remaining() > 0;
    $claimed = $unpaid ? $invoice->claimed() : 0;
    [$label, $value, $tone] = match (true) {
        ! $car->isSold() => ['Вложено в машину', $s['invested'], ''],
        $unpaid && $invoice->isOwed() => [$staff ? 'Отдаём менеджеру' : 'Вам к выплате', $invoice->remaining(), 'text-accent-text'],
        $unpaid => [$staff ? 'Менеджер отдаёт нам' : 'Отдать нам', $invoice->remaining(), $invoice->isOverdue() ? 'text-danger' : ''],
        $car->state === \App\Garage\CarState::Sold && $staff => ['Прибыль', $s['profit'], $s['profit'] < 0 ? 'text-danger' : ''],
        default => ['Продана за', $car->sold_price, ''],
    };
    $row = 'row justify-between';
@endphp
<div class="list">
    <div class="row flex-col items-start gap-1 py-4">
        <span class="text-sm text-ink-muted">{{ $label }}</span>
        <span class="nums text-[32px] leading-none font-bold {{ $tone }}">{{ Money::exact($value) }}</span>
        @if ($claimed > 0)<span class="mt-1 text-sm text-urgent">Сообщили об оплате {{ Money::exact($claimed) }}, ждём подтверждения</span>@endif
    </div>
    @if ($unpaid && ! $invoice->isOwed() && ($link = $invoice->openLink()))
        <x-billing.pay-link :link="$link" :cancel="'/cars/'.$car->offer->number.'/links/'.$link->id"/>
    @endif

    @if ($car->isSold() && $label !== 'Продана за')
        <div class="{{ $row }}"><span>Продана за</span><span class="nums">{{ Money::rub($car->sold_price) }}</span></div>
    @endif
    @if ($car->cost !== null && ! $car->isSold())
        <div class="{{ $row }}"><span>Отдали за</span><span class="nums">{{ Money::rub($car->cost) }}</span></div>
    @endif
    @if (! $car->isSold())
        {{-- Строки складываются в «вложено» у всех: платили мы — отдельной строкой, и менеджеру тоже. --}}
        <div class="{{ $row }}"><span>{{ $s['our_costs'] > 0 ? ($staff ? 'Расходы менеджера' : 'Ваши расходы') : 'Расходы' }}</span><span class="nums">{{ Money::exact($s['manager_costs']) }}</span></div>
        @if ($s['our_costs'] > 0)<div class="{{ $row }}"><span>{{ $staff ? 'Наши расходы' : 'Платили мы' }}</span><span class="nums">{{ Money::exact($s['our_costs']) }}</span></div>@endif
    @else
        <div class="{{ $row }}"><span>Вложено</span><span class="nums">{{ Money::exact($s['invested']) }}</span></div>
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
    @if ($invoice)
        @php $pdf = $invoice->getFirstMedia('file'); @endphp
        <{{ $pdf ? 'a' : 'div' }} @if ($pdf) href="/cars/{{ $car->offer->number }}/invoice/pdf" data-turbo="false" target="_blank" @endif class="{{ $row }}">
            <span class="min-w-0">
                <span class="block">{{ $invoice->isOwed() ? 'К выплате менеджеру' : 'Счёт '.$invoice->label() }}</span>
                <span class="row-sub">{{ $invoice->state->label() }}@if ($unpaid), до {{ $invoice->due_at->translatedFormat('j M') }}@endif</span>
            </span>
            @if ($pdf)<span class="flex shrink-0 items-center gap-1 text-accent-text"><x-ui.icon name="file" class="size-4"/>PDF</span>@endif
        </{{ $pdf ? 'a' : 'div' }}>
    @endif
</div>
