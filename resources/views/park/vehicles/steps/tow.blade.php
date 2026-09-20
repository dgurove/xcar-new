@php use App\Park\{RequestState, ReleasedTo, Inspection}; use App\Support\Money; @endphp
{{-- Шаг «Эвакуация»: назначить (поля) или, когда назначена, чипы и «Выехали» в плашке; «Перенести» — снова через звонок. --}}@if ($req->state === RequestState::New)
    <div class="mt-3 grid grid-cols-2 gap-3">
        <x-ui.field name="planned_at" label="Когда" type="datetime-local" :value="$req->planned_at?->format('Y-m-d\TH:i')"/>
        <x-ui.field name="yard_id" label="Куда" :options="$yards" placeholder="—" :value="$req->yard_id"/>
        <x-ui.field name="from_address" label="Откуда" :value="$req->from_address" span="col-span-2"/>
    </div>
    <div class="mt-4"><x-ui.button id="act-submit" class="w-full sm:w-auto">Назначить</x-ui.button></div>
@else
    <div class="mt-2 flex flex-wrap items-center gap-1.5">
        @if ($req->planned_at)<span class="chip nums {{ $req->isOverdue() ? 'bg-danger-soft text-danger' : '' }}">{{ $req->planned_at->translatedFormat('j M, H:i') }}</span>@endif
        @if ($req->from_address)<x-ui.place class="chip">{{ $req->from_address }}</x-ui.place>@endif
        @if ($req->yard)<x-ui.place class="chip">→ {{ $req->yard->name }}</x-ui.place>@endif
        <a href="/cars/{{ $vehicle->id }}?call=1" class="chip">Перенести</a>
    </div>
    <div class="mt-4"><x-ui.button id="act-submit" class="w-full sm:w-auto">Выехали</x-ui.button></div>
@endif
