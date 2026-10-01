{{-- Поля ТС в деле: пока ТС ожидается — карточка сверху (данные из письма сверяют); после приёма — строка среди фаз,
     раскрывается в ту же форму. save — своя кнопка (когда формы этапа нет). Над полями — группа документов:
     расхождения «в карточке → в документе» и строка «Заполнить из документов» (пока в карточке пусто) или «Сверить
     с документами» (окно «Из документов»); в свёрнутой строке — оранжевое «в документе иначе» и искра. --}}
@php
    $save = $save ?? false;
    $differences = $differences ?? [];
    $scanFiles = ($canManage ?? false) && ($letters ?? 0) ? (new \App\Mail\Scan\VehicleSubject($vehicle))->files() : collect();
    $scan = $scanFiles->isNotEmpty();
    $fill = ! $vehicle->brand_id || ! $vehicle->model_id || ! $vehicle->vin || ! $vehicle->year || ! $vehicle->color;
    $scanUrl = '/cars/'.$vehicle->id.'/scan';
@endphp
@if ($vehicleOpen)
    <x-ui.card title="Транспортное средство">
        <x-park.doc-differences :vehicle="$vehicle" :differences="$differences" class="mb-3">
            @if ($scan)<x-mail.scan-button :url="$scanUrl" :fill="$fill" :files="$scanFiles"/>@endif
        </x-park.doc-differences>
        <x-park.vehicle-fields :vehicle="$vehicle" :vendors="$vendors" :categories="$categories"/>
        @if ($save)<div class="mt-3 flex justify-end"><x-ui.button variant="secondary" size="sm">Сохранить</x-ui.button></div>@endif
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
                @if ($scan)
                    <x-mail.scan-button :url="$scanUrl" look="icon" :class="$fill ? 'scan-wants' : ''"/>
                @endif
                <x-ui.icon name="chevron-down" class="phase-chevron size-5 shrink-0 text-ink-dim"/>
            </span>
        </summary>
        <div class="phase-body px-3 pb-3 pt-1">
            <x-park.doc-differences :vehicle="$vehicle" :differences="$differences" class="mb-3">
                @if ($scan)<x-mail.scan-button :url="$scanUrl" :fill="$fill" :files="$scanFiles"/>@endif
            </x-park.doc-differences>
            <x-park.vehicle-fields :vehicle="$vehicle" :vendors="$vendors" :categories="$categories"/>
            @if ($save)<div class="mt-3 flex justify-end"><x-ui.button variant="secondary" size="sm">Сохранить</x-ui.button></div>@endif
        </div>
    </details>
@endif
