{{-- Поля ТС в деле: пока ТС ожидается — карточка сверху (данные из письма сверяют); после приёма — строка среди фаз,
     раскрывается в ту же форму. save — своя кнопка (когда формы этапа нет). --}}
@php $save = $save ?? false; @endphp
@if ($vehicleOpen)
    <x-ui.card title="Транспортное средство">
        <x-park.vehicle-fields :vehicle="$vehicle" :vendors="$vendors" :categories="$categories"/>
        @if ($save)<div class="mt-3 flex justify-end"><x-ui.button variant="secondary" size="sm">Сохранить</x-ui.button></div>@endif
    </x-ui.card>
@else
    <details class="phase">
        <summary class="row !py-2.5 list-none flex-wrap gap-y-1">
            <span class="chip">Транспортное средство</span>
            @if ($vehicle->ref)<span class="tag nums">{{ $vehicle->ref }}</span>@endif
            @if ($vehicle->vendor)<span class="tag">{{ $vehicle->vendor->name }}</span>@endif
            @if ($vehicle->plate)<span class="tag nums">{{ $vehicle->plate }}</span>@endif
            <x-ui.icon name="chevron-down" class="phase-chevron ml-auto size-5 shrink-0 self-center text-ink-dim"/>
        </summary>
        <div class="phase-body px-3 pb-3 pt-1">
            <x-park.vehicle-fields :vehicle="$vehicle" :vendors="$vendors" :categories="$categories"/>
            @if ($save)<div class="mt-3 flex justify-end"><x-ui.button variant="secondary" size="sm">Сохранить</x-ui.button></div>@endif
        </div>
    </details>
@endif
