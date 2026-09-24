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
            @if ($vehicle->ref)<x-ui.copy-code class="tag" :value="$vehicle->ref"/>@endif
            @if ($vehicle->vendor)<x-vendor.name :vendor="$vehicle->vendor" class="tag"/>@endif
            @if ($vehicle->plate)<x-ui.copy-code class="tag" :value="$vehicle->plate" done="Госномер в буфере"/>@endif
            <x-ui.icon name="chevron-down" class="phase-chevron ml-auto size-5 shrink-0 self-center text-ink-dim"/>
        </summary>
        <div class="phase-body px-3 pb-3 pt-1">
            <x-park.vehicle-fields :vehicle="$vehicle" :vendors="$vendors" :categories="$categories"/>
            @if ($save)<div class="mt-3 flex justify-end"><x-ui.button variant="secondary" size="sm">Сохранить</x-ui.button></div>@endif
        </div>
    </details>
@endif
