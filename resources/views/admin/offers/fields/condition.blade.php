{{-- Поля «Состояние» — одни на редактор и окошко строки. --}}
@php use App\Cars\{DamageCause, DamageZone, Papers}; @endphp
<div class="{{ $grid }}">
    <x-ui.field name="damage_cause" label="Причина" :options="DamageCause::options()" placeholder="—" :value="$offer->damage_cause?->value"/>
    <x-ui.field name="incident_date" label="Дата события" type="date" :value="$offer->incident_date?->toDateString()"/>
    <x-ui.field name="papers" label="Документы" :options="Papers::options()" placeholder="—" :value="$offer->papers?->value" span="col-span-2 lg:col-span-1"/>
    <div class="field col-span-full">
        <span class="field-label">Повреждения</span>
        <div class="flex flex-wrap gap-1.5">
            @foreach (DamageZone::cases() as $zone)
                <label class="choice"><input type="checkbox" name="damage_zones[]" value="{{ $zone->value }}" @checked(in_array($zone->value, old('damage_zones', $offer->damage_zones ?? [])))><span>{{ $zone->label() }}</span></label>
            @endforeach
        </div>
    </div>
    <x-ui.tri name="is_runnable" label="На ходу" :value="$offer->is_runnable"/>
    <x-ui.tri name="has_keys" label="Ключи" :value="$offer->has_keys"/>
    <x-ui.field name="description" label="Описание" type="textarea" :value="$offer->description" span="col-span-full"/>
</div>
