<x-ui.shell title="Сделки" :heading="false">
    <x-admin.work-titles current="sdelki" :count="$deals->total()"/>
    <x-ui.toolbar class="mt-5" :sorts="\App\Http\Admin\DealController::SORTS" :sort="$sort" :pills="\App\Http\Admin\DealController::PRESETS" :pill="$preset" pill-param="preset" name="deals"/>

    @if ($deals->isEmpty())
        <x-ui.empty class="mt-6">Сделок нет.</x-ui.empty>
    @else
        <div class="mt-6 flex flex-col gap-2">
            @foreach ($deals as $deal)
                @php $offer = $deal->offer; $position = $offer->position(); @endphp
                <a href="/predlozheniya/{{ $offer->number }}" class="row items-start">
                    <div class="row-photo"><x-offer.photo :media="$offer->mainPhoto()" sizes="64px"/></div>
                    <div class="min-w-0 flex-1">
                        <div class="flex items-baseline gap-2">
                            <span class="truncate font-medium">{{ $offer->titleWithYear() }}</span>
                            <span class="nums shrink-0 text-sm font-normal text-ink-dim">№ {{ $offer->number }}</span>
                        </div>
                        <div class="text-sm text-ink-muted">{{ $deal->buyer?->name }} · <span class="nums text-ink">{{ number_format($deal->amount, 0, '', ' ') }} ₽</span></div>
                        <div class="mt-1.5 flex flex-wrap items-center gap-1.5">
                            @if ($deal->state !== \App\Offers\DealState::Active)
                                <x-ui.pill :tone="$deal->state === \App\Offers\DealState::Done ? 'open' : 'danger'">{{ $deal->state->label() }}</x-ui.pill>
                            @elseif ($position)
                                <x-ui.pill :tone="$position->isOverdue() ? 'urgent' : 'plain'">{{ $position->stage->block?->name ?? $position->stage->name }}</x-ui.pill>
                                <x-route.clock :position="$position" side="staff"/>
                            @else
                                <x-ui.pill :tone="$offer->state->tone()">{{ $offer->state->label() }}</x-ui.pill>
                            @endif
                        </div>
                    </div>
                </a>
            @endforeach
        </div>
        <div class="mt-8">{{ $deals->links() }}</div>
    @endif
</x-ui.shell>
