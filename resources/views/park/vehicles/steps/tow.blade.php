@php use App\Park\{RequestState, ReleasedTo, Inspection}; use App\Support\Money; @endphp
{{-- Шаг «Эвакуация»: назначить (поля) или, когда назначена, чипы и «Выехали» в плашке; «Перенести» — снова через звонок. --}}@if ($req->state === RequestState::New)
    <div class="mt-3 grid grid-cols-2 gap-3">
        <x-ui.field name="planned_at" label="Когда" type="datetime-local" :value="$req->planned_at?->format('Y-m-d\TH:i')"/>
        <x-ui.field name="yard_id" label="Куда" :options="$yards" placeholder="—" :value="$req->yard_id"/>
        <x-ui.field name="from_address" label="Откуда" :value="$req->from_address" span="col-span-2"/>
        <x-ui.field name="carrier" label="Перевозчик" :value="$req->carrier" list="carriers-list"/>
        <datalist id="carriers-list">@foreach ($carriers as $c)<option value="{{ $c }}">@endforeach</datalist>
        <div class="grid grid-cols-2 gap-3">
            <x-ui.field name="distance_km" label="Км" inputmode="numeric" :value="$req->distance_km"/>
            <x-ui.field name="cost" label="₽" inputmode="numeric" :value="$req->cost ?? $towCost"/>
        </div>
    </div>
@else
    <div class="mt-2 flex flex-wrap items-center gap-1.5">
        @if ($req->planned_at)<span class="chip nums {{ $req->isOverdue() ? 'bg-danger-soft text-danger' : '' }}">{{ $req->planned_at->translatedFormat('j M, H:i') }}</span>@endif
        @if ($req->from_address)<x-ui.place class="chip">{{ $req->from_address }}</x-ui.place>@endif
        @if ($req->yard)<x-ui.place class="chip">→ {{ $req->yard->name }}</x-ui.place>@endif
        @if ($req->carrier)<span class="chip">{{ $req->carrier }}</span>@endif
        @if ($req->distance_km)<span class="chip nums">{{ $req->distance_km }} км</span>@endif
        @if ($req->cost)<span class="chip nums">{{ Money::rub($req->cost) }}</span>@endif
        <a href="/cars/{{ $vehicle->id }}?call=1" class="chip">Перенести</a>
    </div>
@endif
