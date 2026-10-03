{{-- Строка «Работы → Гаража»: название в два этажа (под ним — этап цветным словом и дни на нём, на телефоне ещё вендор
     с убытком), от 640 вендор столбцом, расходы (его и наши) и главное число этапа справа: вложено (в CRM с
     закупочной), к оплате или цена продажи. Нажатие — окошко с путём, деньгами и расходами. --}}
@props(['car'])
@php
    use App\Garage\CarState;
    use App\Garage\Payer;
    use App\Support\Money;
    use App\Support\Plural;
    $offer = $car->offer;
    $days = $car->stageDays();
    $tone = match ($car->state->tone()) { 'urgent' => 'text-urgent', 'open' => 'text-accent-text', 'closed' => 'text-ink-dim', default => 'text-ink-muted' };
    $stage = mb_strtolower($car->state->label()).', '.$days.' '.Plural::of($days, ['день', 'дня', 'дней']);
    $current = $car->payoutInvoice ?? $car->invoice;
    [$value, $caption] = match ($car->state) {
        CarState::Waiting => [$car->cost ?? $car->deal?->cost, $car->cost === null ? 'платит менеджер' : 'отдали за'],
        CarState::Sold => $current && $current->remaining() > 0
            ? [$current->remaining(), $current->isOwed() ? 'отдаём' : ($car->invoice_to === 'buyer' ? 'платит покупатель' : 'отдаёт нам')]
            : [$car->sold_price, $car->invoice ? 'продана за' : 'счёта нет'],
        CarState::Settled => [$car->sold_price, 'продана за'],
        default => [$car->invested(), 'вложено'],
    };
    $ref = trim(($offer->vendor?->name ?? '').' '.($offer->claim_ref ?? ''));
    $spent = $car->spent();
@endphp
<tr data-detail-key="{{ $offer->number }}" id="garage-{{ $offer->number }}">
    <td class="grow">
        <x-ui.row-link :key="$offer->number"><span class="cell-title">{{ $offer->titleWithYear() }}</span></x-ui.row-link>
        <span class="cell-sub"><span class="{{ $tone }}">{{ $stage }}</span>@if ($ref)<span class="sm:hidden">{{ $ref }}</span>@endif</span>
    </td>
    <td class="cell-dim hidden sm:table-cell"><span class="block max-w-56 truncate">{{ $ref }}</span></td>
    <td class="num nums hidden sm:table-cell col-peek-hide">@if ($spent > 0){{ Money::nums($spent) }}@if ($car->spent(Payer::Xcar) > 0)<span class="cell-sub">наши {{ Money::nums($car->spent(Payer::Xcar)) }}</span>@endif @endif</td>
    <td class="num nums">@if ($value){{ Money::nums($value) }}<span class="cell-sub">{{ $caption }}</span>@endif</td>
</tr>
