{{-- Блок «Документы» дела ТС, рядом с «+ Документ»: круглая кнопка с искрой — окно «Из документов» (прочитать документы и
     фото писем и подставить в карточку). Только тем, кто правит ТС, и когда во входящих есть что читать. --}}
@php $scanFiles = $canManage && $letters ? (new \App\Mail\Scan\VehicleSubject($vehicle))->files() : collect(); @endphp
@if ($scanFiles->isNotEmpty())
    <x-mail.scan-button :url="'/cars/'.$vehicle->id.'/scan'" look="round" :fill="! ($vehicle->brand_id && $vehicle->model_id && $vehicle->vin && $vehicle->year && $vehicle->color)"/>
@endif
