{{-- Строка черновика у поставщика: название с типом ТС, № убытка, город, дата заведения и «Вписать цену» справа. Ничего
     нашего: ни авторов, ни источников, ни состояний. Вся строка — ссылка в редактор. --}}
@php $href = '/offers/'.$offer->id; @endphp
<tr id="partner-offer-{{ $offer->id }}" data-search-row>
    @foreach ($table->columns as $key => $col)
        @switch($key)
        @case('title')
    <td class="{{ $col->classes() }}">
        <a href="{{ $href }}" class="row-link"><span class="cell-title"><x-ui.cat-icon :category="$offer->category()"/><span class="cell-name">{{ $offer->title() }}</span></span></a>
        <span class="cell-sub">
            <span class="nums sm:hidden">{{ $offer->claim_ref }}</span>
            @if ($offer->settlement)<span class="sm:hidden">{{ $offer->settlement->title() }}</span>@endif
        </span>
    </td>
        @break
        @case('ref')
    <td class="{{ $col->classes() }}"><x-ui.copy-code :value="$offer->claim_ref"/></td>
        @break
        @case('city')
    <td class="{{ $col->classes() }}">@if ($offer->settlement)<x-ui.place>{{ $offer->settlement->title() }}</x-ui.place>@endif</td>
        @break
        @case('fill')
    <td class="{{ $col->classes() }}"><a href="{{ $href }}" class="pill pill-accent !min-h-6 !py-0 text-sm whitespace-nowrap">Вписать цену</a></td>
        @break
        @case('created')
    <td class="{{ $col->classes() }}">{{ \App\Support\Ago::html($offer->created_at) }}</td>
        @break
        @endswitch
    @endforeach
</tr>
