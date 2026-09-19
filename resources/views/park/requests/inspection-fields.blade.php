{{-- Осмотр по полям акта: показания, комплектность, требует ремонта, повреждения по акту и не по акту. --}}
@php use App\Park\Inspection; $insp = $vehicle->lastInspection(\App\Park\InspectionKind::Intake); $signer ??= 'Кто сдал'; @endphp
<x-ui.card title="Показания">
    <div class="grid grid-cols-2 gap-3">
        <x-ui.field name="mileage" label="Пробег, км" inputmode="numeric" :value="$vehicle->mileage"/>
        <div class="field">
            <span class="field-label">Ключей</span>
            <div class="flex gap-1.5">
                @for ($k = 0; $k <= 3; $k++)<label class="choice"><input type="radio" name="keys_count" value="{{ $k }}" @checked((string) old('keys_count', $insp?->keys_count) === (string) $k)><span class="nums">{{ $k }}</span></label>@endfor
            </div>
        </div>
        <div class="field col-span-full">
            <span class="field-label">Топливо</span>
            <div class="flex flex-wrap gap-1.5">
                @foreach ([0 => 'пусто', 1 => '1/8', 2 => '1/4', 3 => '3/8', 4 => '1/2', 5 => '5/8', 6 => '3/4', 7 => '7/8', 8 => 'полный'] as $f => $label)
                    <label class="choice"><input type="radio" name="fuel" value="{{ $f }}" @checked((string) old('fuel', $vehicle->fuel) === (string) $f)><span class="nums">{{ $label }}</span></label>
                @endforeach
            </div>
        </div>
    </div>
</x-ui.card>
<x-ui.card title="Комплектность">
    <div class="flex flex-wrap gap-1.5">
        @foreach (Inspection::DOCS as $k => $label)<label class="choice"><input type="checkbox" switch name="docs[]" value="{{ $k }}" @checked(in_array($k, old('docs', $insp?->docs ?? []), true))><span>{{ $label }}</span></label>@endforeach
        @foreach (Inspection::EQUIPMENT as $k => $label)<label class="choice"><input type="checkbox" switch name="equipment[]" value="{{ $k }}" @checked(in_array($k, old('equipment', $insp?->equipment ?? []), true))><span>{{ $label }}</span></label>@endforeach
    </div>
    <div class="mt-3 grid grid-cols-3 gap-3">
        @foreach (Inspection::REPAIR as $k => $label)
            <x-ui.tri :name="'repair['.$k.']'" :label="$label" :value="($o = old('repair.'.$k)) === null || $o === '' ? ($insp?->repair[$k] ?? null) : (bool) $o"/>
        @endforeach
    </div>
</x-ui.card>
<x-ui.card title="Повреждения">
    <div class="grid grid-cols-2 gap-3">
        <div class="field col-span-full">
            <div class="flex flex-wrap gap-1.5">
                @foreach ($zones as $zone)
                    <label class="choice"><input type="checkbox" switch name="damage_zones[]" value="{{ $zone->value }}" @checked(in_array($zone->value, old('damage_zones', $vehicle->damage_zones ?? []), true))><span>{{ $zone->label() }}</span></label>
                @endforeach
            </div>
        </div>
        <x-ui.field name="damage_note" label="По акту осмотра" type="textarea" :value="$vehicle->damage_note" span="col-span-full"/>
        @if ($transit)<x-ui.field name="transit_damage" label="При перевозке" type="textarea" span="col-span-full"/>@endif
        <x-ui.field name="missing_parts" label="Нет деталей"/>
        <x-ui.field name="replaced_units" label="Замена агрегатов"/>
    </div>
</x-ui.card>
<x-ui.card :title="$signer">
    <div class="grid grid-cols-2 gap-3">
        <x-ui.field name="signer_name" label="Имя" span="col-span-full"/>
        <x-park.signature/>
    </div>
</x-ui.card>
