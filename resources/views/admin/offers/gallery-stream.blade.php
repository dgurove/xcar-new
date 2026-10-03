{{-- Ответ на действие с кадром. Из карточки строки — его ряд и полоса «Поделиться» (без видимых кадров и цены
     кнопки нет); из редактора — ряд и документы. --}}
@if ($detail ?? false)
<turbo-stream action="replace" target="detail-photos"><template>@include('admin.offers.detail-photos', ['offer' => $offer])</template></turbo-stream>
<turbo-stream action="update" target="detail-tools"><template>@include('admin.offers.detail-tools', ['offer' => $offer])</template></turbo-stream>
{{-- Документ, брошенный в кадры, ложится в документы — их список в карточке тоже свежий. --}}
<turbo-stream action="replace" target="papers"><template>@include('admin.offers.papers', ['offer' => $offer])</template></turbo-stream>
@else
<turbo-stream action="replace" target="gallery"><template>@include('admin.offers.gallery', ['offer' => $offer])</template></turbo-stream>
<turbo-stream action="replace" target="papers"><template>@include('admin.offers.papers', ['offer' => $offer])</template></turbo-stream>
@endif
{{-- Первый документ, который «✨» прочтёт, — искра у «Добавить документ» появляется сразу, последний удалили — пропадает. --}}
<turbo-stream action="replace" target="papers-scan"><template>@include('admin.offers.scan-row', ['offer' => $offer])</template></turbo-stream>
