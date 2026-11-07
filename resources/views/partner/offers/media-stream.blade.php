{{-- Ответ на загрузку, порядок и удаление у поставщика: ряд фото и документы целиком. --}}
<turbo-stream action="replace" target="partner-photos"><template>@include('partner.offers.photos', ['offer' => $offer])</template></turbo-stream>
<turbo-stream action="replace" target="partner-papers"><template>@include('partner.offers.papers', ['offer' => $offer])</template></turbo-stream>
