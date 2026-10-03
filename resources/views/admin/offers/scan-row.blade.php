{{-- Блок «Документы» предложения, у правого края, в строке с «Добавить документ» — один на редактор и карточка: круглая кнопка с искрой, окно
     «Из документов» (Scan\OfferSubject). Читать нечего — ни PDF и картинок в документах, ни файлов в письмах — кнопки нет.
     Обёртка #papers-scan (contents) — её перерисовывает загрузка и удаление документа (gallery-stream): писем там не знают,
     спрашиваем базу. --}}
@php
    $scan = \App\Mail\Scan\OfferSubject::for($offer, auth()->user());
    $scanFiles = isset($letters) && ! $letters ? $scan->papers() : $scan->files();
@endphp
<div id="papers-scan" class="contents">
@if ($scanFiles->isNotEmpty())
    <x-mail.scan-button :url="'/offers/'.$offer->number.'/scan'" look="round" :papers="true" :fill="! ($offer->brand_id && $offer->model_id && $offer->vin && $offer->year && $offer->color)"/>
@endif
</div>
