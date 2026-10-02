{{-- Блок «Документы» дела ТС, первой строкой: вход в окно «Из документов» — прочитать документы и фото писем и подставить
     в карточку. «Заполнить из документов», пока нет марки, модели, VIN, года или цвета, иначе «Сверить с документами».
     Только тем, кто правит ТС, и когда во входящих есть что читать. --}}
@php $scanFiles = $canManage && $letters ? (new \App\Mail\Scan\VehicleSubject($vehicle))->files() : collect(); @endphp
@if ($scanFiles->isNotEmpty())
    <div class="list mb-3"><x-mail.scan-button :url="'/cars/'.$vehicle->id.'/scan'" :fill="! ($vehicle->brand_id && $vehicle->model_id && $vehicle->vin && $vehicle->year && $vehicle->color)" :files="$scanFiles"/></div>
@endif
