<turbo-stream action="replace" target="fav-{{ $offer->number }}"><template><x-offer.favorite :offer="$offer"/></template></turbo-stream>
<turbo-stream action="replace" target="fav-{{ $offer->number }}-compact"><template><x-offer.favorite :offer="$offer" variant="compact"/></template></turbo-stream>
<turbo-stream action="replace" target="fav-{{ $offer->number }}-pill"><template><x-offer.favorite :offer="$offer" variant="pill"/></template></turbo-stream>
<turbo-stream action="replace" target="fav-{{ $offer->number }}-bar"><template><x-offer.favorite :offer="$offer" variant="bar"/></template></turbo-stream>
{{-- Число — как у шапки (`Nav::totals`): только видимые человеку, иначе закладки ушедших машин раздували его после нажатия. --}}
@php $count = \App\Offers\Offer::visibleTo(auth()->user())->whereHas('favorites', fn ($f) => $f->where('user_id', auth()->id()))->count(); @endphp
<turbo-stream action="update" targets="[data-total='/account/favorites']"><template>{{ \App\Support\Nav::short($count) }}</template></turbo-stream>
