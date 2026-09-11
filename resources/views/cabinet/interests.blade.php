<x-ui.shell title="Мои запросы">
    <div class="flex flex-col gap-2">
        @forelse ($interests as $interest)
            <a href="/offers/{{ $interest->offer->number }}" class="row">
                <div class="row-photo"><x-offer.photo :media="$interest->offer->mainPhoto()" sizes="64px"/></div>
                <div class="min-w-0 flex-1">
                    <div class="truncate font-medium">{{ $interest->offer->titleWithYear() }}</div>
                    <div class="text-sm text-ink-muted">{{ $interest->state->label() }} · {{ $interest->created_at->translatedFormat('j M') }}</div>
                </div>
            </a>
        @empty
            <p class="text-ink-muted">Запросов пока нет.</p>
        @endforelse
    </div>
    <div class="mt-4">{{ $interests->links() }}</div>
</x-ui.shell>
