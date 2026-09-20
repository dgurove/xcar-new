@php $view = \App\Support\ListView::pick(request(), $vehicles->total()); @endphp
<x-ui.shell title="Наличие" :count="$vehicles->total()">
    <x-ui.toolbar :sorts="\App\Http\Park\VehicleController::SORTS" :sort="$sort" :pills="$pills" :pill="$pill" pill-param="yard" :counts="$counts" :hidden="array_filter(['vendor' => request('vendor'), 'state' => request('state'), \App\Support\ListView::PARAM => request(\App\Support\ListView::PARAM)])" name="vehicles">
        <x-slot:extra><x-ui.view-switch :current="$view"/></x-slot:extra>
        <x-slot:filters>
            <input type="search" name="q" value="{{ $q }}" placeholder="Номер, VIN, госномер, марка" class="field-input" enterkeyhint="search">
            <select name="vendor" class="field-input"><option value="">Все вендоры</option>@foreach ($vendors as $id => $name)<option value="{{ $id }}" @selected((string) request('vendor') === (string) $id)>{{ $name }}</option>@endforeach</select>
        </x-slot:filters>
    </x-ui.toolbar>
    @if ($vehicles->isEmpty())
        <x-ui.empty class="mt-6">ТС нет</x-ui.empty>
    @else
        @if ($view === \App\Support\ListView::TABLE)
            <x-ui.table id="vehicles" class="mt-6" :open="$peek">
                <x-slot:head>
                    <tr>
                        <th>№</th>
                        <th class="grow">Марка, модель</th>
                        <th>Состояние</th>
                        <th class="hidden sm:table-cell">Парковка</th>
                        <th class="hidden sm:table-cell">Вендор</th>
                        <th class="num">Дней</th>
                    </tr>
                </x-slot:head>
                @foreach ($vehicles as $vehicle)<x-park.table-row :vehicle="$vehicle"/>@endforeach
            </x-ui.table>
        @else
        <div class="mt-6 {{ \App\Support\ListView::containerClass($view) }}" data-controller="ticker">
            @foreach ($vehicles as $vehicle)<x-park.card :vehicle="$vehicle" :debt="$debts[$vehicle->id] ?? 0"/>@endforeach
        </div>
        @endif
        <div class="mt-8"><x-ui.pager :of="$vehicles" :sizes="\App\Support\ListView::perSizes($view)"/></div>
    @endif
</x-ui.shell>
