{{-- Строка ссылки на оплату — одна на всё приложение CRM (09.10.2026): «По ссылкам» видом строками, деньги сделки,
     «Ссылка уже есть», карточка счёта. Как строка в банке: сверху кто платит и сумма на одной линии, под ними ТС (данные —
     режутся «…») и статус коротким словом под суммой (`MoneyLinks::short`). detail — открывает карточку рядом со списком
     (строка «Оплат»), иначе переход на её адрес. car=false — ТС не пишем (деньги сделки: машина та же); вместо неё
     sub. Слот acts — маленькая кнопка в строке (копировать у открытой). --}}
@props(['link', 'detail' => false, 'car' => true, 'sub' => null, 'href' => null])
@php
    use App\Http\Admin\MoneyLinks as L; use App\Support\Money; use App\Billing\Acquiring\PayLinkState;
    [$state, $tone] = L::short($link);
    $line = $car ? (L::carLine($link) ?? L::who($link)) : $sub;
    $href ??= $detail ? \App\Support\Detail::url($link->id, ['tab' => 'links']) : \App\Http\Admin\MoneyController::linkUrl($link);
@endphp
<div {{ $attributes->class(['row money-row', 'opacity-50' => $link->state === PayLinkState::Canceled]) }} @if ($detail) data-detail-key="{{ $link->id }}" data-search-row @endif>
    <a href="{{ $href }}" class="money-row-main" @if ($detail) data-detail-link data-turbo-frame="detail" data-turbo-prefetch="false" @else data-turbo-frame="_top" @endif>
        <span class="money-row-line">
            <span class="money-row-title">{{ $link->payerName() }}</span>
            <span class="nums {{ $link->state === PayLinkState::Paid ? 'text-open' : '' }}">{{ Money::rub($link->amount) }}</span>
        </span>
        <span class="money-row-line money-row-sub">
            <span>{{ $line }}</span>
            <span class="{{ $tone }}">{{ $state }}</span>
        </span>
    </a>
    {{ $acts ?? '' }}
</div>
