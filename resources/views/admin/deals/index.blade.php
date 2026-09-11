<x-ui.shell title="Сделки" :wide="true">
    <div class="mb-4 flex flex-col gap-3">
        <x-ui.sort :items="\App\Http\Admin\DealController::SORTS" :current="$sort"/>
        <x-ui.presets :items="\App\Http\Admin\DealController::PRESETS" :current="$preset"/>
    </div>

    @if ($deals->isEmpty())
        <div class="py-24 text-center text-ink-muted">Сделок нет</div>
    @else
        <div class="flex flex-col gap-2">
            @foreach ($deals as $deal)
                @php $offer = $deal->offer; $position = $offer->position(); @endphp
                <a href="/admin/offers/{{ $offer->number }}" class="row items-start">
                    <div class="row-photo"><x-offer.photo :media="$offer->mainPhoto()" sizes="64px"/></div>
                    <div class="min-w-0 flex-1">
                        <div class="flex items-baseline gap-2">
                            <span class="truncate font-medium">{{ $offer->titleWithYear() }}</span>
                            <span class="shrink-0 text-sm text-ink-muted">№ {{ $offer->number }}</span>
                        </div>
                        <div class="text-sm text-ink-muted">{{ $deal->buyer?->name }} · <span class="font-semibold text-ink tabular-nums">{{ number_format($deal->amount, 0, '', ' ') }} ₽</span></div>
                        <div class="mt-1.5 flex flex-wrap gap-1.5">
                            @if ($deal->state !== \App\Offers\DealState::Active)
                                <span class="chip {{ $deal->state === \App\Offers\DealState::Done ? 'bg-open-soft text-open' : 'bg-danger-soft text-danger' }}">{{ $deal->state->label() }}</span>
                            @elseif ($position)
                                <x-route.status :position="$position"/>
                            @else
                                <x-offer.state :state="$offer->state"/>
                            @endif
                        </div>
                    </div>
                </a>
            @endforeach
        </div>
        <div class="mt-4">{{ $deals->links() }}</div>
    @endif
</x-ui.shell>
