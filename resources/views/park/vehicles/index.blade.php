{{-- «Наличие»: что стоит на парковках сейчас. Пилюли — парковки, поиск слева в тулбаре ищет по ходу набора
     (по всем состояниям, выданную тоже найдёт) и адрес не меняет; вид — таблица с окошком или карточки. --}}
@php use App\Support\ListView; $view = ListView::pick(request(), $vehicles->total()); @endphp
<x-ui.shell title="Наличие" :count="$vehicles->total()">
    <x-ui.toolbar :sorts="\App\Http\Park\VehicleController::SORTS" :sort="$sort" :pills="$pills" :pill="$pill" pill-param="yard" :counts="$counts"
                  :hidden="array_filter(['vendor' => request('vendor'), 'state' => request('state'), ListView::PARAM => request(ListView::PARAM)])" name="vehicles"
                  search="Номер, VIN, госномер, марка" search-target="#cars" :q="$q">
        <x-slot:extra><x-ui.view-switch :current="$view"/></x-slot:extra>
        <x-slot:filters>
            <select name="vendor" class="field-input"><option value="">Все вендоры</option>@foreach ($vendors as $id => $name)<option value="{{ $id }}" @selected((string) request('vendor') === (string) $id)>{{ $name }}</option>@endforeach</select>
        </x-slot:filters>
    </x-ui.toolbar>
    <div class="mt-4" id="cars">@include('park.vehicles.list')</div>
</x-ui.shell>
