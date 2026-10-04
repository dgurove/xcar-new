{{-- Поля ТС в деле: пока ТС ожидается — карточка сверху (данные из письма сверяют); после приёма — строка среди фаз,
     раскрывается в ту же форму. Правка полей — «Сохранить изменения» (x-ui.save-bar): в своей форме — она и есть; в форме
     шага — тем же нажатием, но адресом /cars/{id}/fields: сохраняются только поля ТС, шаг не выполняется. Над полями — расхождения «в карточке →
     в документе» с «Взять», в свёрнутой строке — оранжевое «в документе иначе». Вход в окно «Из документов» — в блоке
     «Документы» (park/vehicles/scan-row). --}}
@php
    $save = $save ?? false;
    $differences = $differences ?? [];
@endphp
@if ($vehicleOpen)
    <x-ui.card title="Транспортное средство">
        <x-park.doc-differences :vehicle="$vehicle" :differences="$differences" class="mb-3"/>
        <div data-save-scope><x-park.vehicle-fields :vehicle="$vehicle" :vendors="$vendors" :categories="$categories"/></div>
        <x-ui.save-bar page class="mt-3" :action="$save ? null : '/cars/'.$vehicle->id.'/fields'"/>
    </x-ui.card>
@else
    <details class="phase" id="vehicle-fields">
        <summary class="row !py-2.5 list-none">
            <span class="flex min-w-0 flex-1 flex-wrap items-center gap-1.5">
                @if ($vehicle->ref)<x-ui.copy-code class="tag" :value="$vehicle->ref"/>@endif
                @if ($vehicle->vendor)<x-vendor.name :vendor="$vehicle->vendor" class="tag"/>@endif
                @if ($vehicle->plate)<x-ui.copy-code class="tag" :value="$vehicle->plate" done="Госномер в буфере"/>@endif
                @if ($differences)<x-ui.state tone="urgent">в документе иначе</x-ui.state>@endif
            </span>
            <span class="flex shrink-0 items-center gap-2 self-center">
                <x-ui.icon name="chevron-down" class="phase-chevron size-5 shrink-0 text-ink-dim"/>
            </span>
        </summary>
        <div class="phase-body px-3 pb-3 pt-1">
            <x-park.doc-differences :vehicle="$vehicle" :differences="$differences" class="mb-3"/>
            <div data-save-scope><x-park.vehicle-fields :vehicle="$vehicle" :vendors="$vendors" :categories="$categories"/></div>
            <x-ui.save-bar page class="mt-3" :action="$save ? null : '/cars/'.$vehicle->id.'/fields'"/>
        </div>
    </details>
@endif
