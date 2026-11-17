{{-- Строка «Гаража»: название (от 640 этап — своим столбцом «Этап», 29.10.2026); на телефоне под ним статус, ниже логотип вендора
     с убытком. Статус (17.11.2026, владелец: «очень непонятные описания», «почему подготовка, если переоценка», «зачем писать, кто
     вывезет», «просрочено — просто красным») — одной дверью `CarLine::crmStatuses` (prop `status`): пока идёт сделка со
     страховой — статус её шага (`Position::status`: наше дело зелёным, кого ждём серым, просрочено красным, сколько — подсказкой),
     после — этап гаража серым; где машина и кто её везёт — в окне. На телефоне (и в краткой таблице рядом с окном) справа
     одно главное число этапа (`CarLine`: закупочная, к оплате, продана за). От 640 вместо него — столбцы, каждый своё:
     вендор, «Закупочная», «Расходы», «Цена продажи»; шире — «В гараже с». Нажатие — карточка с путём, деньгами и расходами.
     Срок обязывающего, пока сделка не заплатила поставщику, — красным таймером у номера убытка, только когда горит. --}}
@props(['car', 'table', 'status' => null])
@php
    use App\Support\Money;
    $offer = $car->offer;
    $line = \App\Garage\CarLine::for($car, auth()->user(), crm: true);
    $vendor = $offer->vendor_id ? \App\Vendors\Vendor::badges()->get($offer->vendor_id) : null;
    $hasRef = $vendor || $offer->claim_ref;
    // Оплата поставщику — та же закупочная, в расходах её второй раз нет.
    $spent = $car->extraSpent();
    $price = $car->price();
    // Сделка со страховой идёт — её шаг и чей ход строкой ниже, как в «Сделках».
    $sale = $car->dealOpen() ? $offer->positions->first(fn ($p) => $p->track === \App\Workflow\Track::Sale) : null;
    // Срок обязывающего, пока поставщику не заплачено (19.10.2026): только горящий — красной меткой справа от номера убытка
    // (17.11.2026, владелец: у названия «скачет»); номера нет — у названия.
    $binding = $sale && \App\Offers\Binding::matters($offer);
@endphp
<tr data-detail-key="{{ $offer->number }}" id="garage-{{ $offer->number }}">
    @foreach ($table->columns as $key => $col)
        @switch($key)
        @case('title')
    <td class="{{ $col->classes() }}">
        <x-cell.title :key="$offer->number" :offer="$offer">@if ($binding && ! $hasRef)<x-offer.binding :offer="$offer" icon/>@endif</x-cell.title>
        <span class="cell-sub">@if ($status)<span class="sm:hidden {{ $status['tone'] }}" @if ($status['hint']) title="{{ $status['hint'] }}" @endif>{{ $status['text'] }}</span>@endif{{ "" }}@if ($hasRef)<span class="block sm:hidden"><x-vendor.ref :vendor="$vendor" :ref="$offer->claim_ref">@if ($binding)<x-offer.binding :offer="$offer" icon/>@endif</x-vendor.ref></span>@endif</span>
    </td>
        @break
        @case('vendor')
    <td class="{{ $col->classes() }}"><x-vendor.ref :vendor="$vendor" :ref="$offer->claim_ref" cell>@if ($binding)<x-offer.binding :offer="$offer" icon/>@endif</x-vendor.ref></td>
        @break
        @case('stage')
    <td class="{{ $col->classes() }}">@if ($status)<span class="whitespace-normal {{ $status['tone'] }}" @if ($status['hint']) title="{{ $status['hint'] }}" @endif>{{ $status['text'] }}</span>@endif</td>
        @break
        @case('manager')
    <td class="{{ $col->classes() }}">@if ($car->manager)<x-cell.person :user="$car->manager" from="lg"/>@else<span class="text-ink-muted">под себя</span>@endif</td>
        @break
        @case('taken')
    <td class="{{ $col->classes() }}">{{ \App\Support\Ago::html($car->taken_at) }}</td>
        @break
        @case('price')
    <td class="{{ $col->classes() }}">@if ($price){{ Money::nums($price) }}@endif</td>
        @break
        @case('spent')
    <td class="{{ $col->classes() }}">@if ($spent > 0){{ Money::nums($spent) }}@endif</td>
        @break
        @case('sold')
    <td class="{{ $col->classes() }}">@if ($car->sold_price){{ Money::nums($car->sold_price) }}@endif</td>
        @break
        @case('sum')
    <td class="{{ $col->classes() }}">@if ($line->caption){{ Money::nums($line->value) }}<span class="cell-sub">{{ $line->caption }}</span>@endif</td>
        @break
        @endswitch
    @endforeach
</tr>
