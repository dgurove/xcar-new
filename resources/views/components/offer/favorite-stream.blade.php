<turbo-stream action="replace" target="fav-{{ $offer->number }}"><template><x-offer.favorite :offer="$offer"/></template></turbo-stream>
<turbo-stream action="replace" target="fav-{{ $offer->number }}-compact"><template><x-offer.favorite :offer="$offer" variant="compact"/></template></turbo-stream>
<turbo-stream action="replace" target="fav-{{ $offer->number }}-pill"><template><x-offer.favorite :offer="$offer" variant="pill"/></template></turbo-stream>
@php $count = \App\Offers\Favorite::where('user_id', auth()->id())->count(); @endphp
<turbo-stream action="update" targets="[data-total='/account/favorites']"><template>{{ \App\Support\Nav::short($count) }}</template></turbo-stream>
