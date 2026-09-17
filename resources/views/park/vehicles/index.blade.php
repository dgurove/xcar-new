<x-ui.shell :title="$yard ? $yard->name : 'Машины'" :count="$vehicles->total()" :back="$yard ? ['Стоянки', '/yards'] : false">
    <x-ui.toolbar :sorts="\App\Http\Park\VehicleController::SORTS" :sort="$sort" :pills="\App\Http\Park\VehicleController::PRESETS" :pill="$preset" pill-param="preset" :counts="$counts" :hidden="array_filter(['yard' => request('yard'), \App\Support\ListView::PARAM => request(\App\Support\ListView::PARAM)])" name="vehicles">
        <x-slot:extra><x-ui.view-switch/></x-slot:extra>
        <x-slot:filters><input name="q" value="{{ $q }}" placeholder="Номер, VIN, госномер, марка" class="field-input field-s"></x-slot:filters>
    </x-ui.toolbar>
    @if ($vehicles->isEmpty())
        <x-ui.empty class="mt-6">Машин нет</x-ui.empty>
    @else
        @php $view = \App\Support\ListView::fromRequest(request()); @endphp
        @if ($view === \App\Support\ListView::TABLE)
            <x-ui.table id="vehicles" class="mt-6">
                <x-slot:head>
                    <tr>
                        <th>Машина</th>
                        <th class="w-32">Состояние</th>
                        <th class="hidden w-36 sm:table-cell">Стоянка</th>
                        <th class="hidden w-40 sm:table-cell">Клиент</th>
                        <th class="num w-16 sm:w-24">Дней</th>
                    </tr>
                </x-slot:head>
                @foreach ($vehicles as $vehicle)<x-park.table-row :vehicle="$vehicle"/>@endforeach
            </x-ui.table>
        @else
        <div class="mt-6 {{ \App\Support\ListView::containerClass($view) }}" data-controller="ticker">
            @foreach ($vehicles as $vehicle)<x-park.card :vehicle="$vehicle"/>@endforeach
        </div>
        @endif
        <div class="mt-8">{{ $vehicles->links() }}</div>
    @endif
</x-ui.shell>
