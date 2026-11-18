{{-- Вопрос при входе (10.11.2026, владелец: «Забрал» и «Беру в работу» за менеджеров жали админы): на «Предложениях» и «Сделках»
     шторкой сама — дело, которое знает только он: «Берёте в работу?» сразу, «Забрали автомобиль?» и «Связались с владельцем?»
     — через сутки после просьбы. Ответ — те же кнопки шага (`x-deal.ask-action`), «Попросить вывоз…» — на получении.
     Доведена до ума 18.11.2026 (владелец: «шторка нужна — хоть немного спасает от того, что менеджеры ленятся; подумай, как
     её довести до ума»). Что было не так и как стало (`ask_sheet_controller`):
     — дела шли по одному на вход (три дела — три входа): теперь подряд — ответил или нажал «Ещё нет», и следующее встаёт
       сразу, без нового входа; в заголовке — сколько ждёт;
     — закрыл жестом или мимо — молча считалось «Ещё нет» до завтра: теперь закрытие ничего не откладывает, шторка просто
       молчит три часа на этом устройстве (дела горят счётчиком «Сделок» и лентой «Ждут вас»); до завтра — только кнопкой;
     — нажал на машину посмотреть, о чём речь, — дело откладывалось: теперь нет;
     — после «назад» выезжала снова из снимка страницы: теперь только при настоящем входе;
     — не было видно, давно ли просят: строка под вопросом — сколько ждёт ответа, срок вышел — красным.
     «Ещё нет» — до завтра (`requirements.prompted_at`), как было. --}}
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
            $stage->asksConsent() => 'Взять в работу?',
            $stage->isPickupStep() => 'Забрали автомобиль?',
            (bool) preg_match('/связал/iu', (string) $ask->moves->first()?->label) => 'Связались с владельцем?',
            default => \App\Users\Wording::mine($ask->title),
        };
        $late = (bool) $ask->due?->isPast();
        $since = $late ? $ask->due : $ask->requirement->created_at;
    @endphp
    <div data-controller="sheet ask-sheet">
        <x-ui.sheet id="ask-sheet" :title="$asks->count() > 1 ? 'Ждут ответа: '.$asks->count() : 'Ждёт ответа'">
            {{-- Машина — ссылкой на сделку: посмотреть, о чём речь; дело при этом не откладывается. --}}
            <a href="{{ $deal->href() }}" class="ask-sheet-car" data-action="ask-sheet#look">
                <span class="row-photo"><x-offer.photo :media="$offer->mainPhoto()" :cat="$offer->category()->icon()" sizes="72px" :cat="$offer->category()?->icon()"/></span>
                <span class="min-w-0 flex-1"><span class="block truncate font-medium">{{ $offer->titleWithYear() }}</span>
                    <span class="nums block text-sm text-ink-muted">{{ \App\Support\Money::rub($deal->isGarage() ? (int) $deal->cost : (int) $deal->amount) }}</span></span>
                <x-ui.icon name="chevron-right" class="size-5 shrink-0 text-ink-dim"/>
            </a>
            <h3 class="mt-5 text-lg">{{ $question }}</h3>
            {{-- Давно ли просят: срок вышел — красным, сколько просрочено; иначе серым, сколько ждёт ответа. --}}
            @if ($since)
                <p class="mt-1 text-sm {{ $late ? 'text-danger' : 'text-ink-muted' }}">{{ $late ? 'Просрочено на' : 'Ждёт ответа' }} <span class="nums whitespace-nowrap" data-controller="timer" data-timer-since-value="{{ $since->toIso8601String() }}" data-timer-coarse-value="true">{{ \App\Support\Ago::since($since, hours: true) }}</span></p>
            @endif
            <div class="sheet-foot mt-4 flex flex-col gap-2" data-action="submit->ask-sheet#answered">
                <x-deal.ask-action :ask="$ask" variant="sheet"/>
                <form method="post" action="/deals/{{ $deal->id }}/later">
                    @csrf<input type="hidden" name="back" value="{{ request()->getRequestUri() }}">
                    <x-ui.button variant="ghost" block>Ещё нет, напомнить завтра</x-ui.button>
                </form>
            </div>
        </x-ui.sheet>
    </div>
@endif
