{{-- Подтверждения менеджера строками: фото, название, чипы № и дата, справа цена и состояние. --}}
<x-ui.cabinet title="Подтверждения">
    @if ($bids->isEmpty())
        <x-ui.empty href="/" link="В каталог">Вы ещё не подтверждали предложения.</x-ui.empty>
    @else
        <div class="flex flex-col gap-2">
            @foreach ($bids as $bid)
                @php $offer = $bid->offer; @endphp
                <a href="/offers/{{ $offer->number }}" class="row">
                    <span class="row-photo"><x-offer.photo :media="$offer->mainPhoto()" sizes="72px"/></span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate font-medium">{{ $offer->titleWithYear() }}</span>
                        <span class="row-sub"><span class="tag nums">№ {{ $offer->number }}</span><span class="tag nums">{{ $bid->created_at->translatedFormat('j M') }}</span></span>
                    </span>
                    <span class="flex shrink-0 flex-col items-end gap-1.5">
                        <span class="nums font-medium">{{ \App\Support\Money::rub($bid->amount) }}</span>
                        <x-ui.pill class="!min-h-0 !py-1 text-xs" :tone="match ($bid->state) { \App\Offers\BidState::Accepted => 'open', \App\Offers\BidState::Active => 'urgent', \App\Offers\BidState::Declined => 'danger', default => 'closed' }">{{ $bid->state->label() }}</x-ui.pill>
                    </span>
                </a>
            @endforeach
        </div>
        {{ $bids->links() }}
    @endif
</x-ui.cabinet>
