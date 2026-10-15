{{-- Строка «Предложений» вендора: название, под ним номер убытка (копируется) и где сейчас — на телефоне; от 640 номер
     своим столбцом, от 768 «Где» словом вкладки (`Offer::crmTab`). Подтверждения и интерес — сколько было всего, цены. «Запрос» — когда номер отправили вендору; дата — кнопка «Снять метку запроса?» (на телефоне — во второй
     строке). Нажатие — редактор предложения (`row-go`). --}}
@props(['offer', 'base'])
@php use App\Support\Money; $tab = $offer->crmTab(); @endphp
<tr>
    <td class="grow">
        <a href="/offers/{{ $offer->number }}" class="row-link"><span class="cell-title"><x-ui.cat-icon :category="$offer->category()"/><span class="cell-name">{{ $offer->titleWithYear() }}</span></span></a>
        <span class="cell-sub">@if ($offer->claim_ref)<span class="sm:hidden"><x-ui.copy-code :value="$offer->claim_ref"/></span>@endif<span class="md:hidden">{{ mb_lcfirst($tab) }}</span>@if ($offer->requested_at)<span class="sm:hidden">запрос {{ $offer->requested_at->translatedFormat('j M') }}</span>@endif</span>
    </td>
    <td class="cell-dim hidden sm:table-cell">@if ($offer->claim_ref)<x-ui.copy-code :value="$offer->claim_ref"/>@endif</td>
    <td class="hidden whitespace-nowrap text-ink-muted md:table-cell">{{ $tab }}</td>
    <td class="num nums">@if ($offer->bids_count)<span class="text-accent-text">{{ $offer->bids_count }}</span>@endif</td>
    <td class="num nums">@if ($offer->interests_count)<span class="text-accent-text">{{ $offer->interests_count }}</span>@endif</td>
    <td class="num nums hidden whitespace-nowrap sm:table-cell">@if ($offer->floor_price){{ Money::exact($offer->floor_price) }}@endif</td>
    <td class="num nums hidden whitespace-nowrap sm:table-cell">@if ($offer->asking_price){{ Money::exact($offer->asking_price) }}@endif</td>
    <td class="num nums hidden whitespace-nowrap sm:table-cell">
        @if ($offer->requested_at)
            <form method="post" action="{{ $base }}/offers/{{ $offer->number }}/request" data-turbo-confirm="Снять метку запроса с {{ $offer->claim_ref }}?" data-turbo-confirm-label="Снять">@csrf @method('delete')<button class="text-ink-muted underline decoration-dotted underline-offset-4" title="{{ $offer->requested_at->translatedFormat('j M Y, H:i') }}">{{ $offer->requested_at->translatedFormat('j M') }}</button></form>
        @endif
    </td>
</tr>
