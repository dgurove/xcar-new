{{-- «Наличие»: что стоит на парковках сейчас. Чипы — вендор, парковка, тип ТС и «Без ставки», лупа ищет по ходу
     набора (по всем состояниям, выданную тоже найдёт) и адрес не меняет; вид — таблица с окошком или карточки. --}}
@php use App\Support\ListView; $view = ListView::pick(request(), $vehicles->total()); @endphp
<x-ui.shell title="Наличие" :count="$vehicles->total()">
    <x-ui.toolbar :sorts="\App\Http\Park\VehicleController::SORTS" :sort="$sort" name="vehicles" :facets="$facets"
                  search="Номер, VIN, госномер, марка" search-target="#cars" :q="$q">
        <x-slot:extra><x-ui.view-switch :current="$view"/></x-slot:extra>
    </x-ui.toolbar>
    <div class="mt-4" id="cars">@include('park.vehicles.list')</div>
</x-ui.shell>
