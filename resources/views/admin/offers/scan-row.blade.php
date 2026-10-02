{{-- Блок «Документы» предложения, первой строкой — один на редактор и окошко: «Заполнить из документов», пока нет
     марки, модели, VIN, года или цвета, иначе «Сверить с документами» (окно «Из документов», Scan\OfferSubject). Нет
     файлов во входящих — строки нет. --}}
@php $scanFiles = $letters ? (new \App\Mail\Scan\OfferSubject($offer))->files() : collect(); @endphp
@if ($scanFiles->isNotEmpty())
    <div class="list mb-3"><x-mail.scan-button :url="'/offers/'.$offer->number.'/scan'" :fill="! ($offer->brand_id && $offer->model_id && $offer->vin && $offer->year && $offer->color)" :files="$scanFiles"/></div>
@endif
