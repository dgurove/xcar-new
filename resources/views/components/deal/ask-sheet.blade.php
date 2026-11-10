{{-- Вопрос при входе (10.11.2026, владелец: «Забрал» и «Беру в работу» за менеджеров жали админы): на «Предложениях» и «Сделках»
     шторкой сама — дело, которое знает только он: «Берёте в работу?» сразу, «Забрали автомобиль?» и «Связались с владельцем?»
     — через сутки после просьбы. По одному: ответил — следующее при следующем входе; «Ещё нет» и закрытие — до завтра
     (`requirements.prompted_at`). Ответ — те же кнопки шага (`x-deal.ask-action`), «Попросить вывоз…» — на получении. --}}
@php
    use App\Offers\ManagerAsk;
    $user = auth()->user();
    $asks = $user?->hasDeals() && ! $user->is_demo && request()->is('offers', 'deals')
        ? ManagerAsk::of($user)->filter(fn (ManagerAsk $a) => $a->taps() && $a->requirement
            && ($a->requirement->prompted_at === null || $a->requirement->prompted_at->lt(today()))
            && ($a->requirement->stage?->asksConsent() || $a->requirement->created_at->lt(now()->subDay())))->values()
        : collect();
    $ask = $asks->first();
@endphp
@if ($ask)
    @php
        $deal = $ask->deal;
        $offer = $deal->offer;
        $stage = $ask->requirement->stage;
        $question = match (true) {
            $stage->asksConsent() => 'Берёте в работу?',
            $stage->isPickupStep() => 'Забрали автомобиль?',
            (bool) preg_match('/связал/iu', (string) $ask->moves->first()?->label) => 'Связались с владельцем?',
            default => \App\Users\Wording::mine($ask->title),
        };
    @endphp
    <div data-controller="sheet ask-sheet" data-ask-sheet-later-value="/deals/{{ $deal->id }}/later">
        <x-ui.sheet id="ask-sheet" :title="$asks->count() > 1 ? '1 из '.$asks->count() : 'Сделка'">
            <a href="{{ $deal->href() }}" class="ask-sheet-car">
                <span class="row-photo"><x-offer.photo :media="$offer->mainPhoto()" sizes="72px"/></span>
                <span class="min-w-0"><span class="block truncate font-medium">{{ $offer->titleWithYear() }}</span>
                    <span class="nums block text-sm text-ink-muted">{{ \App\Support\Money::rub($deal->isGarage() ? (int) $deal->cost : (int) $deal->amount) }}</span></span>
            </a>
            <h3 class="mt-5 text-lg">{{ $question }}</h3>
            <div class="sheet-foot mt-4 flex flex-col gap-2">
                <x-deal.ask-action :ask="$ask" variant="sheet"/>
                <form method="post" action="/deals/{{ $deal->id }}/later">
                    @csrf<input type="hidden" name="back" value="{{ request()->getRequestUri() }}">
                    <x-ui.button variant="ghost" block>Ещё нет</x-ui.button>
                </form>
            </div>
        </x-ui.sheet>
    </div>
@endif
