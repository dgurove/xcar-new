{{-- Карточка машины в «Работе → Гараже»: кадры, название с этапом словом у него, ниже фразой — чья, кто платил поставщику и
     сколько дней на этапе (10.10.2026: прежде чип человека и слово этапа стояли рядом капсулами); дальше то же, что в
     карточке «Гараж» редактора — путь, деньги (с закупочной), расходы и действия сотрудника. «Открыть страницу» —
     редактор предложения в CRM. --}}
@php $offer = $garageView['offer']; $car = $garageView['car']; @endphp
<x-ui.detail>
    <x-ui.row-card :href="'/offers/'.$offer->number.'#garage'" :title="$offer->titleWithYear()" :photos="$offer->visiblePhotos()" :action="false">
        <x-slot:badge><x-ui.state :tone="$car->state->tone()" class="title-state">{{ mb_strtolower($car->state->label()) }}</x-ui.state></x-slot:badge>
        <x-slot:line>
            <span>{{ $car->manager?->shortName() ?? 'взяли под себя' }}</span>
            @if ($car->deal?->garagePayerLine())<span>{{ $car->deal->garagePayerLine() }}</span>@endif
            @unless ($car->isSold())<span>{{ \App\Garage\CarLine::days($car->stageDays()) }} на этапе</span>@endunless
        </x-slot:line>
        <div class="mt-4">@include('admin.offers.garage-card', ['routeHere' => true])</div>
    </x-ui.row-card>
</x-ui.detail>
