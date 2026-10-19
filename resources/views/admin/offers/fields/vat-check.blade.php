{{-- «С НДС» — у заголовка «Цены» (19.10.2026, владелец: «это же всех цен касается»), не у закупочной: окошко строки
     (`price-steps`) и карточка «Цены» редактора. Галка — поле формы через `form` ($form); при смене вендора её ставит
     `vendor_vat_controller`. --}}
<span class="flags pt-0"><x-ui.check name="prices_include_vat" :checked="old('prices_include_vat', $offer->prices_include_vat)" :form="$form ?? null">С НДС</x-ui.check></span>
