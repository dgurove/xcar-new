{{-- Поля ТС в деле: пока ТС ожидается — карточка сверху (данные из письма сверяют); после приёма — строка среди фаз,
     раскрывается в ту же форму. save — своя кнопка (когда формы этапа нет). У полей — «✨ Из документов» (окно
     «Распознать»), а когда карточка и документы расходятся — строки «в карточке → в документе» над полями и
     оранжевое «в документе иначе» в свёрнутой строке. --}}
@php
    $save = $save ?? false;
    $differences = $differences ?? [];
    $scan = ($canManage ?? false) && ($letters ?? 0);
@endphp
@if ($vehicleOpen)
    <x-ui.card title="Транспортное средство">
        @if ($scan)
            <x-slot:actions>
                <x-mail.scan-button :url="'/cars/'.$vehicle->id.'/scan'"/>
            </x-slot:actions>
        @endif
        <x-park.doc-differences :vehicle="$vehicle" :differences="$differences" class="mb-3"/>
        <x-park.vehicle-fields :vehicle="$vehicle" :vendors="$vendors" :categories="$categories"/>
        @if ($save)<div class="mt-3 flex justify-end"><x-ui.button variant="secondary" size="sm">Сохранить</x-ui.button></div>@endif
    </x-ui.card>
@else
    <details class="phase">
        <summary class="row !py-2.5 list-none flex-wrap gap-y-1">
            @if ($vehicle->ref)<x-ui.copy-code class="tag" :value="$vehicle->ref"/>@endif
            @if ($vehicle->vendor)<x-vendor.name :vendor="$vehicle->vendor" class="tag"/>@endif
            @if ($vehicle->plate)<x-ui.copy-code class="tag" :value="$vehicle->plate" done="Госномер в буфере"/>@endif
            @if ($differences)<x-ui.state tone="urgent">в документе иначе</x-ui.state>@endif
            <span class="ml-auto flex items-center gap-2 self-center">
                @if ($scan)
                    <x-mail.scan-button :url="'/cars/'.$vehicle->id.'/scan'"/>
                @endif
                <x-ui.icon name="chevron-down" class="phase-chevron size-5 shrink-0 text-ink-dim"/>
            </span>
        </summary>
        <div class="phase-body px-3 pb-3 pt-1">
            <x-park.doc-differences :vehicle="$vehicle" :differences="$differences" class="mb-3"/>
            <x-park.vehicle-fields :vehicle="$vehicle" :vendors="$vendors" :categories="$categories"/>
            @if ($save)<div class="mt-3 flex justify-end"><x-ui.button variant="secondary" size="sm">Сохранить</x-ui.button></div>@endif
        </div>
    </details>
@endif
