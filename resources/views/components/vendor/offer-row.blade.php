{{-- Строка «Предложений» вендора: название, под ним номер убытка (копируется) и где сейчас — на телефоне; от 640 номер
     своим столбцом, от 768 «Где» словом вкладки (`Offer::crmTab`). Подтверждения и интерес — сколько было всего, цены,
     дата заведения. Нажатие — редактор предложения (`row-go`). --}}
@props(['offer'])
@php use App\Support\Money; $tab = $offer->crmTab(); @endphp
<tr>
    <td class="grow">
        <a href="/offers/{{ $offer->number }}" class="row-link"><span class="cell-title"><x-ui.cat-icon :category="$offer->category()"/><span class="cell-name">{{ $offer->titleWithYear() }}</span></span></a>
        <span class="cell-sub">@if ($offer->claim_ref)<span class="sm:hidden"><x-ui.copy-code :value="$offer->claim_ref"/></span>@endif<span class="md:hidden">{{ mb_lcfirst($tab) }}</span></span>
    </td>
    <td class="cell-dim hidden sm:table-cell">@if ($offer->claim_ref)<x-ui.copy-code :value="$offer->claim_ref"/>@endif</td>
    <td class="hidden whitespace-nowrap text-ink-muted md:table-cell">{{ $tab }}</td>
    <td class="num nums">@if ($offer->bids_count)<span class="text-accent-text">{{ $offer->bids_count }}</span>@endif</td>
    <td class="num nums">@if ($offer->interests_count)<span class="text-accent-text">{{ $offer->interests_count }}</span>@endif</td>
    <td class="num nums hidden whitespace-nowrap sm:table-cell">@if ($offer->floor_price){{ Money::exact($offer->floor_price) }}@endif</td>
    <td class="num nums hidden whitespace-nowrap sm:table-cell">@if ($offer->asking_price){{ Money::exact($offer->asking_price) }}@endif</td>
    <td class="num nums hidden whitespace-nowrap !text-xs !text-ink-dim lg:table-cell">{{ $offer->created_at?->translatedFormat('j M Y') }}</td>
</tr>
