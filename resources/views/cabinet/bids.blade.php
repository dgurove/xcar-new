<x-ui.shell title="Мои ставки">
    <div class="flex flex-col gap-2">
        @forelse ($bids as $bid)
            <a href="/offers/{{ $bid->offer->number }}" class="row">
                <div class="row-photo"><x-offer.photo :media="$bid->offer->mainPhoto()" sizes="64px"/></div>
                <div class="min-w-0 flex-1">
                    <div class="truncate font-medium">{{ $bid->offer->titleWithYear() }}</div>
                    <div class="text-sm text-ink-muted">{{ $bid->state->label() }} · {{ $bid->created_at->translatedFormat('j M') }}</div>
                </div>
                <x-offer.price :amount="$bid->amount"/>
            </a>
        @empty
            <p class="text-ink-muted">Ставок пока нет.</p>
        @endforelse
    </div>
    <div class="mt-4">{{ $bids->links() }}</div>
</x-ui.shell>
