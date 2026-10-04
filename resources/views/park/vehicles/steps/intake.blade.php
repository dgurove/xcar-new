@php use App\Park\{RequestState, ReleasedTo, Inspection}; use App\Support\Money; @endphp
{{-- Шаг «Приём»: площадка, место, когда, ключи, документы, фото при приёме. Осмотра пока нет — будет модулем. --}}
<div class="mt-3 grid grid-cols-2 gap-3" data-controller="spots" data-spots-map-value="{{ json_encode($yardRows) }}">
    <x-ui.field name="yard_id" label="Парковка" :options="$yards" :value="$req->yard_id ?? $yards->keys()->first()" required data-spots-target="yard" data-action="change->spots#sync"/>
    <x-ui.field name="spot" label="Место" list="spots-list" autocapitalize="characters"/>
    <datalist id="spots-list" data-spots-target="list"></datalist>
    <x-ui.field name="accepted_at" label="Когда" type="datetime-local" :value="now()->format('Y-m-d\TH:i')"/>
    <div class="field">
        <span class="field-label">Ключей</span>
        <div class="flex gap-1.5">
            @for ($k = 0; $k <= 3; $k++)<label class="choice"><input type="radio" name="keys_count" value="{{ $k }}" @checked((string) old('keys_count', '1') === (string) $k)><span class="nums">{{ $k }}</span></label>@endfor
        </div>
    </div>
    <div class="field col-span-full">
        <span class="field-label">Документы</span>
        <div class="flex flex-wrap gap-1.5">
            @foreach (Inspection::DOCS as $k => $label)<label class="choice"><input type="checkbox" switch name="docs[]" value="{{ $k }}" @checked(in_array($k, old('docs', []), true))><span>{{ $label }}</span></label>@endforeach
        </div>
    </div>
</div>
{{-- Кадры приёма — обычным блоком с добавлением (`x-park.photos`), как после приёма в деле. --}}
<x-park.photos :vehicle="$vehicle" :stage="\App\Park\PhotoStage::Intake" nested edit class="mt-3"/>
<div class="mt-4"><x-ui.button id="act-submit" class="w-full sm:w-auto">Принять</x-ui.button></div>
