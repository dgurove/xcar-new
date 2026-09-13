<x-ui.cabinet title="Подтверждения" :trail="[['Главная', '/'], ['Кабинет', '/lk'], ['Подтверждения']]">
    @if ($bids->isEmpty())
        <x-ui.empty href="/" link="В каталог">Вы ещё не подтверждали предложения.</x-ui.empty>
    @else
        <div class="space-y-3">
            @foreach ($bids as $bid)
                @php $offer = $bid->offer; @endphp
                <div class="box flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center sm:gap-4">
                    <a href="/offers/{{ $offer->number }}" class="min-w-0 sm:flex-1">
                        <div class="hover:text-accent-text">{{ $offer->titleWithYear() }}</div>
                        <div class="mt-1.5 flex flex-wrap gap-1.5"><span class="tag nums">№ {{ $offer->number }}</span><span class="tag nums">{{ $bid->created_at->translatedFormat('j M') }}</span></div>
                    </a>
                    <div class="flex items-center justify-between gap-3 sm:contents">
                        <div class="nums sm:text-right">{{ number_format($bid->amount, 0, '', ' ') }} ₽</div>
                        <x-ui.pill :tone="match ($bid->state) { \App\Offers\BidState::Accepted => 'open', \App\Offers\BidState::Active => 'urgent', \App\Offers\BidState::Declined => 'danger', default => 'closed' }">{{ $bid->state->label() }}</x-ui.pill>
                    </div>
                </div>
            @endforeach
        </div>
        <div class="mt-8">{{ $bids->links() }}</div>
    @endif
</x-ui.cabinet>
