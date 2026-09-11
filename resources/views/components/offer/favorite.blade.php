@props(['offer'])
@php $on = $offer->isFavoriteOf(auth()->user()); @endphp
<form method="post" action="/offers/{{ $offer->number }}/izbrannoe" id="fav-{{ $offer->number }}" {{ $attributes }}>
    @csrf
    <button class="flex size-10 items-center justify-center rounded-full bg-chrome/60 text-white {{ $on ? 'text-accent' : '' }}" aria-label="{{ $on ? 'Убрать из избранного' : 'В избранное' }}" @guest data-turbo="false" @endguest>
        <x-ui.icon name="heart" class="size-5 {{ $on ? 'fill-accent text-accent' : '' }}"/>
    </button>
</form>
