{{-- Partner XCar (07.11.2026): черновики вендора поставщика без закупочной цены — таблицей, как CRM «Без закупочной».
     Нажатие — редактор; «+ Новое» — окно «Номер убытка» (`OfferController::find`). Вписал цену — строка уходит. --}}
<x-ui.shell title="Предложения" :count="$offers->total()" :phone-heading="false">
    <x-ui.toolbar :sort="$sort" name="partner-offers" :facets="$facets" search="Номер, марка, VIN">
        <x-slot:actions>
            <div class="contents" data-controller="sheet">
                <button type="button" class="btn btn-s btn-accent shrink-0 rounded-full" data-action="sheet#open"><x-ui.icon name="plus" class="size-4"/><span>Новое</span></button>
                <x-ui.sheet id="claim-find" title="Новое предложение">
                    <form method="post" action="/offers/find" class="flex flex-col gap-3">
                        @csrf
                        <div class="fields">
                            <x-ui.field name="claim_ref" label="Номер убытка" :value="old('claim_ref')" required autocomplete="off"/>
                        </div>
                        <x-ui.sheet-foot>Дальше</x-ui.sheet-foot>
                    </form>
                </x-ui.sheet>
            </div>
        </x-slot:actions>
    </x-ui.toolbar>

    <div class="mt-6" id="list">
        @if ($offers->isEmpty())
            <x-ui.empty>{{ $searching ? 'Ничего не нашлось' : 'Все цены вписаны' }}</x-ui.empty>
        @else
            @php $columns = \App\Http\Partner\OfferController::table(); @endphp
            <x-ui.table :table="$columns" id="partner-offers" :view="\App\Support\ListView::TABLE" :portion="$offers">
                <x-slot:head><x-table.head :table="$columns" :sort="$sort"/></x-slot:head>
                @foreach ($offers as $offer)@include('partner.offers.row', ['offer' => $offer, 'table' => $columns])@endforeach
            </x-ui.table>
        @endif
    </div>
</x-ui.shell>
