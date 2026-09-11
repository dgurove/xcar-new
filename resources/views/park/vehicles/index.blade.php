<x-ui.shell :title="$yard ? $yard->name : 'Машины'" :count="$vehicles->total()" :trail="$yard ? [['Стоянка', '/'], ['Стоянки', '/stoyanki'], [$yard->name]] : [['Стоянка', '/'], ['Машины']]">
    <x-ui.toolbar :sorts="\App\Http\Park\VehicleController::SORTS" :sort="$sort" :pills="\App\Http\Park\VehicleController::PRESETS" :pill="$preset" pill-param="preset" :counts="$counts" :hidden="array_filter(['yard' => request('yard'), \App\Support\ListView::PARAM => request(\App\Support\ListView::PARAM)])" name="vehicles">
        <x-slot:extra><x-ui.view-switch/></x-slot:extra>
        <x-slot:filters><input name="q" value="{{ $q }}" placeholder="Номер, VIN, госномер, марка" class="field-input field-s"></x-slot:filters>
    </x-ui.toolbar>
    @if ($vehicles->isEmpty())
        <x-ui.empty class="mt-6">Машин нет.</x-ui.empty>
    @else
        <div class="mt-6 {{ \App\Support\ListView::containerClass(\App\Support\ListView::fromRequest(request())) }}" data-controller="ticker">
            @foreach ($vehicles as $vehicle)<x-park.card :vehicle="$vehicle"/>@endforeach
        </div>
        <div class="mt-8">{{ $vehicles->links() }}</div>
    @endif
</x-ui.shell>
