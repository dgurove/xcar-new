{{-- Шаг «Приём»: марка и модель, если письмо их не дало, парковка, место, когда, ставка, ключи, документы, фото при приёме
     (`steps/intake`). Внизу «Принять и сформировать письмо» (дальше письмо вендору в этом же окне) и тихое «Принять без
     письма» (акт приёма ждёт задачей); вендору без отчётов — одна «Принять». Заявки нет — её заводит `intakeCar`. --}}
<x-park.act-frame title="Приём" :vehicle="$vehicle" :self="$self">
    <form method="post" action="/cars/{{ $vehicle->id }}/intake" class="act-fill">
        @csrf
        @if ($advance)<input type="hidden" name="advance" value="1">@endif
        @if ($gaps)
            <input type="hidden" name="vehicle_form" value="1">
            <div class="grid grid-cols-2 gap-3">
                <x-ui.combobox name="brand_id" label="Марка" url="/reference/brands" create="/reference/brands" :value="old('brand_id', $vehicle->brand_id)" :text="$vehicle->brand?->name" resets="#cb-model_id" required/>
                <x-ui.combobox name="model_id" label="Модель" url="/reference/models" create="/reference/models" depends="#cb-brand_id [name=brand_id]" :value="old('model_id', $vehicle->model_id)" :text="$vehicle->model?->name" required/>
            </div>
        @endif
        @include('park.vehicles.steps.intake', ['footSubmit' => true])
        <div class="sheet-foot flex flex-col items-center gap-1">
            @if ($letter)
                <button type="submit" name="then" value="letter" class="btn btn-accent w-full">Принять и сформировать письмо</button>
                <button type="submit" name="then" value="none" class="act-quiet">Принять без письма</button>
            @else
                <button type="submit" class="btn btn-accent w-full">Принять</button>
            @endif
        </div>
    </form>
</x-park.act-frame>
