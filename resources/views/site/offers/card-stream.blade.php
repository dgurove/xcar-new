@if (!$visible)
<turbo-stream action="remove" target="offer-{{ $offer->number }}"></turbo-stream>
@elseif ($present)
<turbo-stream action="replace" target="offer-{{ $offer->number }}"><template><x-offer.tile :offer="$offer"/></template></turbo-stream>
@else
<turbo-stream action="prepend" target="catalog"><template><x-offer.tile :offer="$offer"/></template></turbo-stream>
@endif
