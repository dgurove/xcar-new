{{-- После автосохранения карточки: свежая строка таблицы и полоса карточки («Поделиться», ✨). --}}
<turbo-stream action="replace" targets="tr[data-detail-key=&quot;{{ $offer->number }}&quot;]"><template><x-offer.table-row :offer="$offer" :gallery="$offer->isGallery()"/></template></turbo-stream>
<turbo-stream action="update" target="peek-tools"><template>@include('admin.offers.peek-tools', ['offer' => $offer])</template></turbo-stream>
