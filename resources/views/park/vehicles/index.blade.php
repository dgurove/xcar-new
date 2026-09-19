@php $view = \App\Support\ListView::pick(request(), $vehicles->total()); @endphp
<x-ui.shell :title="$yard ? $yard->name : 'ТС'" :count="$vehicles->total()" :back="$yard ? ['Стоянки', '/yards'] : false" :phone-heading="(bool) $yard">
    <x-ui.toolbar :sorts="\App\Http\Park\VehicleController::SORTS" :sort="$sort" :pills="\App\Http\Park\VehicleController::PRESETS" :pill="$preset" pill-param="preset" :counts="$counts" :hidden="array_filter(['stoyanka' => request('stoyanka'), \App\Support\ListView::PARAM => request(\App\Support\ListView::PARAM)])" name="vehicles">
        <x-slot:extra><x-ui.view-switch :current="$view"/></x-slot:extra>
        <x-slot:filters><input name="q" value="{{ $q }}" placeholder="Номер, VIN, госномер, марка" class="field-input field-s"></x-slot:filters>
    </x-ui.toolbar>
    @if ($vehicles->isEmpty())
        <x-ui.empty class="mt-6">ТС нет</x-ui.empty>
    @else
        @if ($view === \App\Support\ListView::TABLE)
            <x-ui.table id="vehicles" class="mt-6">
                <x-slot:head>
                    <tr>
                        <th>№</th>
                        <th class="grow">Марка, модель</th>
                        <th>Состояние</th>
                        <th class="hidden sm:table-cell">Стоянка</th>
                        <th class="hidden sm:table-cell">Клиент</th>
                        <th class="num">Дней</th>
                    </tr>
                </x-slot:head>
                @foreach ($vehicles as $vehicle)<x-park.table-row :vehicle="$vehicle"/>@endforeach
            </x-ui.table>
        @else
        <div class="mt-6 {{ \App\Support\ListView::containerClass($view) }}" data-controller="ticker">
            @foreach ($vehicles as $vehicle)<x-park.card :vehicle="$vehicle"/>@endforeach
        </div>
        @endif
        <div class="mt-8"><x-ui.pager :of="$vehicles" :sizes="\App\Support\ListView::perSizes($view)"/></div>
    @endif
</x-ui.shell>
