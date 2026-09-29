{{-- Ответ на действие с кадром. Из окошка строки — его ряд и полоса «Поделиться» (без видимых кадров и цены
     кнопки нет); из редактора — ряд и документы. --}}
@if ($peek ?? false)
<turbo-stream action="replace" target="peek-photos"><template>@include('admin.offers.peek-photos', ['offer' => $offer])</template></turbo-stream>
<turbo-stream action="update" target="peek-tools"><template>@include('admin.offers.peek-tools', ['offer' => $offer])</template></turbo-stream>
@else
<turbo-stream action="replace" target="gallery"><template>@include('admin.offers.gallery', ['offer' => $offer])</template></turbo-stream>
<turbo-stream action="replace" target="papers"><template>@include('admin.offers.papers', ['offer' => $offer])</template></turbo-stream>
@endif
