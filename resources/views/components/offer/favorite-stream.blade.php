<turbo-stream action="replace" target="fav-{{ $offer->number }}"><template><x-offer.favorite :offer="$offer"/></template></turbo-stream>
<turbo-stream action="replace" target="fav-{{ $offer->number }}-compact"><template><x-offer.favorite :offer="$offer" variant="compact"/></template></turbo-stream>
@php $count = \App\Offers\Favorite::where('user_id', auth()->id())->count(); @endphp
<turbo-stream action="update" targets="[data-badge='/account/favorites']"><template>@if ($count)<span class="badge">{{ $count > 99 ? '99+' : $count }}</span>@endif</template></turbo-stream>
