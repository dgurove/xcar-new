{{-- Блок «Документы» предложения, рядом с «Добавить документ» — один на редактор и окошко: круглая кнопка с искрой, окно
     «Из документов» (Scan\OfferSubject). Нет файлов во входящих — кнопки нет. --}}
@php $scanFiles = ($letters ?? 0) ? (new \App\Mail\Scan\OfferSubject($offer))->files() : collect(); @endphp
@if ($scanFiles->isNotEmpty())
    <x-mail.scan-button :url="'/offers/'.$offer->number.'/scan'" look="round" :fill="! ($offer->brand_id && $offer->model_id && $offer->vin && $offer->year && $offer->color)"/>
@endif
