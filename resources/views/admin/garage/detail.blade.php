{{-- Карточка машины в «Работе → Гараже»: кадры, название, этап и чья; ниже то же, что в карточке «Гараж» редактора —
     путь, деньги (с закупочной), расходы и действия сотрудника. «Открыть страницу» — редактор предложения в CRM. --}}
@php $offer = $garageView['offer']; @endphp
<x-ui.detail>
    <x-ui.row-card :href="'/offers/'.$offer->number.'#garage'" :title="$offer->titleWithYear()" :photos="$offer->visiblePhotos()" :action="false">
        <div class="mt-4">@include('admin.offers.garage-card')</div>
    </x-ui.row-card>
</x-ui.detail>
