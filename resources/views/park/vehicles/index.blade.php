<x-ui.shell :title="$yard ? $yard->name : 'Машины'" :back="$yard ? '/stoyanki' : null" :wide="true">
    <div class="mb-4 flex flex-col gap-3">
        <form method="get" class="flex gap-2" data-controller="autosubmit">
            @foreach (request()->except('q', 'page') as $k => $v)@if (!is_array($v))<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endif @endforeach
            <label class="relative flex-1">
                <x-ui.icon name="search" class="pointer-events-none absolute left-3 top-1/2 size-5 -translate-y-1/2 text-ink-dim"/>
                <input type="search" name="q" value="{{ $q }}" placeholder="Номер, VIN, госномер, марка" class="field-input !bg-surface pl-11" enterkeyhint="search">
            </label>
            <x-ui.sort :items="\App\Http\Park\VehicleController::SORTS" :current="$sort"/>
        </form>
        <x-ui.presets :items="\App\Http\Park\VehicleController::PRESETS" :current="$preset" :counts="$counts"/>
    </div>
    @if ($vehicles->isEmpty())
        <div class="py-24 text-center text-ink-muted">Машин нет</div>
    @else
        <div class="flex flex-col gap-2">
            @foreach ($vehicles as $vehicle)
                <x-park.vehicle-row :vehicle="$vehicle"><x-park.state :vehicle="$vehicle"/></x-park.vehicle-row>
            @endforeach
        </div>
        <div class="mt-4">{{ $vehicles->links() }}</div>
    @endif
</x-ui.shell>
