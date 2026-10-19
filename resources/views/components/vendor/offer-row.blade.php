{{-- Строка «Предложений» вендора: название, под ним номер убытка (копируется) и где сейчас — на телефоне; от 640 номер
     своим столбцом, от 768 «Где» словом вкладки (`Offer::crmTab`). Подтверждения и интерес — сколько было всего, цены. «Запрос» — когда номер отправили вендору, ссылкой на шторку того запроса в «Запросах» (на телефоне — во второй
     строке); присланное вендором письмом — серое «письмом». Нажатие — редактор предложения (`row-go`). --}}
@props(['offer', 'base', 'binding' => true])
@php use App\Support\Money; $tab = $offer->crmTab(); $sent = $offer->claimRequest; @endphp
<tr>
    <td class="grow">
        <a href="/offers/{{ $offer->number }}" class="row-link"><span class="cell-title"><x-ui.cat-icon :category="$offer->category()"/><span class="cell-name">{{ $offer->titleWithYear() }}</span></span></a>
        <span class="cell-sub">@if ($offer->claim_ref)<span class="sm:hidden"><x-ui.copy-code :value="$offer->claim_ref"/></span>@endif<span class="md:hidden">{{ mb_lcfirst($tab) }}</span>@if ($offer->by_letter)<span>письмом</span>@endif @if ($sent)<span class="sm:hidden">запрос {{ $sent->created_at->translatedFormat('j M') }}</span>@endif</span>
    </td>
    <td class="cell-dim hidden sm:table-cell">@if ($offer->claim_ref)<x-ui.copy-code :value="$offer->claim_ref"/>@endif</td>
    <td class="hidden whitespace-nowrap text-ink-muted md:table-cell">{{ $tab }}</td>
    {{-- Срок обязывающего (19.10.2026): горит всерьёз — красным (`binding_panic` из выборки). --}}
    @if ($binding)<td @class(['hidden whitespace-nowrap nums md:table-cell', 'font-semibold text-danger' => $offer->binding_panic, 'text-ink-muted' => ! $offer->binding_panic])>{{ $offer->binding_until ? \App\Offers\Binding::date($offer->binding_until) : '' }}</td>@endif
    <td class="num nums">@if ($offer->bids_count)<span class="text-accent-text">{{ $offer->bids_count }}</span>@endif</td>
    <td class="num nums">@if ($offer->interests_count)<span class="text-accent-text">{{ $offer->interests_count }}</span>@endif</td>
    <td class="num nums hidden whitespace-nowrap sm:table-cell">@if ($offer->floor_price){{ Money::exact($offer->floor_price) }}@endif</td>
    <td class="num nums hidden whitespace-nowrap sm:table-cell">@if ($offer->asking_price){{ Money::exact($offer->asking_price) }}@endif</td>
    <td class="num nums hidden whitespace-nowrap sm:table-cell">
        @if ($sent)<a href="{{ $base }}?pill=requests&open={{ $sent->id }}" class="text-ink-muted underline decoration-dotted underline-offset-4">{{ $sent->created_at->translatedFormat('j M') }}</a>@endif
    </td>
</tr>
