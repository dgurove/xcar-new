{{-- «Наличие»: что стоит на парковках сейчас. Пилюли — парковки, поиск слева в тулбаре ищет по ходу набора
     (по всем состояниям, выданную тоже найдёт) и адрес не меняет; вид — таблица с окошком или карточки. --}}
@php
    use App\Support\ListView;
    $view = ListView::pick(request(), $vehicles->total());
    // Выбор галочками и «В продажу» — только админу и только в таблице: галочки лежат в её строках.
    $admin = auth()->user()->isAdmin();
    $select = $admin && ListView::isTable($view) && request()->boolean('select');
@endphp
<x-ui.shell title="Наличие" :count="$vehicles->total()" :phone-heading="false">
    <x-ui.toolbar :sorts="\App\Http\Park\VehicleController::SORTS" :sort="$sort" :pills="$pills" :pill="$pill" pill-param="yard" :counts="$counts"
                  :hidden="array_filter(['vendor' => request('vendor'), 'state' => request('state'), 'gap' => $gap, 'select' => $select ? 1 : null, ListView::PARAM => request(ListView::PARAM)])" name="vehicles"
                  search="Номер, VIN, госномер, марка" search-target="#cars" :q="$q">
        @if ($noRate)
            {{-- «Без ставки» — пока такие ТС есть: у них не заполнены тип или стоимость, либо у вендора нет прайса.
                 Правят их в окошке строки. --}}
            <x-slot:pillsExtra>
                <a href="{{ $gap ? request()->fullUrlWithoutQuery(['gap', 'page']) : request()->fullUrlWithQuery(['gap' => 'rate', 'page' => null]) }}" class="pill {{ $gap ? '' : 'pill-danger' }}" data-turbo-action="replace" @if ($gap) aria-current="true" @endif>Без ставки <span class="nums opacity-70">{{ $noRate }}</span></a>
            </x-slot:pillsExtra>
        @endif
        <x-slot:extra>
            <x-ui.view-switch :current="$view"/>
            @if ($admin && ListView::isTable($view))
                <a href="{{ $select ? request()->fullUrlWithoutQuery(['select', 'page']) : request()->fullUrlWithQuery(['select' => 1, 'page' => null]) }}" class="btn btn-round btn-quiet" data-turbo-action="replace" aria-label="{{ $select ? 'Готово' : 'Выбрать' }}" @if ($select) aria-current="true" @endif><x-ui.icon :name="$select ? 'x' : 'check-circle'" class="size-5"/></a>
            @endif
        </x-slot:extra>
        <x-slot:filters>
            <select name="vendor" class="field-input"><option value="">Все вендоры</option>@foreach ($vendors as $id => $name)<option value="{{ $id }}" @selected((string) request('vendor') === (string) $id)>{{ $name }}</option>@endforeach</select>
        </x-slot:filters>
    </x-ui.toolbar>
    <div class="mt-4" id="cars">@include('park.vehicles.list')</div>
    @if ($select)
        {{-- Выбранные ТС уходят в продажу одной пачкой: у каждой в CRM появляется черновик предложения. --}}
        <form id="sale-form" method="post" action="/cars/to-sale" data-controller="pick-cars" data-pick-cars-verb-value="В продажу" data-turbo-confirm="Отправить выбранные ТС в продажу? В CRM появятся черновики предложений">@csrf
            <x-ui.action-bar><button class="btn btn-accent" disabled><span data-pick-cars-target="label">В продажу</span></button></x-ui.action-bar>
        </form>
    @endif
</x-ui.shell>
