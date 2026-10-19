{{-- Карточка машины в «Работе → Гараже»: кадры, название с этапом словом у него, ниже фразой — чья, кто платил поставщику и
     сколько дней на этапе (10.10.2026: прежде чип человека и слово этапа стояли рядом капсулами); справа от названия главное
     число строки с подписью (`CarLine`: закупочная, после продажи — что с деньгами), «⋯» — в полосе окна; дальше то же, что в
     карточке «Гараж» редактора — путь, деньги (сверху закупочная), расходы и действия сотрудника, письма по машине строками. «Открыть страницу» —
     редактор предложения в CRM. --}}
@php $offer = $garageView['offer']; $car = $garageView['car']; $line = \App\Garage\CarLine::for($car, auth()->user(), crm: true); @endphp
<x-ui.detail>
    <x-ui.row-card :href="'/offers/'.$offer->number.'#garage'" :title="$offer->titleWithYear()" :photos="$offer->visiblePhotos()" :action="false">
        <x-slot:badge><x-ui.state :tone="$car->state->tone()" class="title-state">{{ mb_strtolower($car->state->label()) }}</x-ui.state></x-slot:badge>
        @if ($line->caption)
            <x-slot:aside>
                <div class="nums whitespace-nowrap text-lg font-semibold">{{ \App\Support\Money::rub($line->value) }}</div>
                <div class="text-xs text-ink-muted">{{ $line->caption }}</div>
            </x-slot:aside>
        @endif
        @if ($garageView['more'])
            <x-slot:tools><button type="button" class="bar-btn" data-controller="emit" data-action="emit#send" data-emit-event-param="car-more:open" aria-label="Ещё"><x-ui.icon name="more" class="size-[18px]"/></button></x-slot:tools>
        @endif
        <x-slot:line>
            <span>{{ $car->manager?->shortName() ?? 'взяли под себя' }}</span>
            @if ($car->deal?->garagePayerLine())<span>{{ $car->deal->garagePayerLine() }}</span>@endif
            @unless ($car->isSold())<span>{{ \App\Garage\CarLine::days($car->stageDays()) }} на этапе</span>@endunless
            {{-- Срок обязывающего, пока поставщику не заплачено, — только горящий (19.10.2026). --}}
            @if ($car->dealOpen() && ($bindingWord = \App\Offers\Binding::word($offer)))<span class="nums font-medium text-danger">обязывающее {{ $offer->binding_until->isPast() ? $bindingWord : 'до '.\App\Offers\Binding::date($offer->binding_until).', '.$bindingWord }}</span>@endif
        </x-slot:line>
        <div class="mt-4">@include('admin.offers.garage-card', ['routeHere' => true])</div>
        @if ($letters)
            <section class="mt-5">
                <h2 class="detail-section">Письма <span class="nums font-normal text-ink-dim">{{ $letters }}</span></h2>
                <x-mail.letters :messages="$messages" :url="'/offers/'.$offer->number.'/letters'" :asks="$asks" :stages="$stages"/>
            </section>
        @endif
        {{-- «Вернуть в продажу» из «⋯» карточки — шторка с ценами (в редакторе её ставит сам редактор). --}}
        <x-offer.resale-sheet :offer="$offer"/>
        @include('garage.cars.actions', ['buttons' => [], 'more' => $garageView['more'], 'menuOnly' => true] + $garageView)
    </x-ui.row-card>
</x-ui.detail>
