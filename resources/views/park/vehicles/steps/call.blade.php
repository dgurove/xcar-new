@php use App\Park\{RequestState, ReleasedTo, Inspection}; use App\Support\Money; @endphp
{{-- Шаг «Звонок»: крупный телефон, три исхода кнопками-радио; каждый раскрывает свои поля, подпись кнопки плашки     меняется по исходу (reveal: data-reveal-label). --}}
@php $phone = $req->contact_phone ?? $vehicle->contact_phone; $who = $req->contact_name ?? $vehicle->contact_name; @endphp
@if ($phone)
    <a href="tel:+{{ preg_replace('/\D+/', '', $phone) }}" class="my-3 flex items-center gap-3 rounded-(--radius-l) bg-surface-2 px-4 py-3">
        <span class="btn btn-accent btn-round shrink-0"><x-ui.icon name="phone" class="size-5"/></span>
        <span class="min-w-0"><span class="block text-lg nums">{{ $phone }}</span>@if ($who)<span class="block truncate text-sm text-ink-muted">{{ $who }}</span>@endif</span>
    </a>
@endif
<div class="flex flex-wrap gap-1.5">
    <label class="choice"><input type="radio" name="outcome" value="tow" data-action="reveal#pick" data-reveal-label="Назначить эвакуатор" @checked(old('outcome', 'tow') === 'tow')><span>Нужен эвакуатор</span></label>
    <label class="choice"><input type="radio" name="outcome" value="self" data-action="reveal#pick" data-reveal-label="Ждём" @checked(old('outcome') === 'self')><span>Привезёт сам</span></label>
    <label class="choice"><input type="radio" name="outcome" value="missed" data-action="reveal#pick" data-reveal-label="Перезвоню" @checked(old('outcome') === 'missed')><span>Не дозвонился</span></label>
</div>
<div class="mt-3 grid grid-cols-2 gap-3" data-reveal-target="pane" data-reveal-key="tow">
    <x-ui.field name="planned_at" label="Когда" type="datetime-local" :value="$req->planned_at?->format('Y-m-d\TH:i')"/>
    <x-ui.field name="yard_id" label="Куда" :options="$yards" placeholder="—" :value="$req->yard_id"/>
    <x-ui.field name="from_address" label="Откуда" :value="$req->from_address" span="col-span-2"/>
    <x-ui.field name="carrier" label="Перевозчик" :value="$req->carrier" list="carriers-list"/>
    <datalist id="carriers-list">@foreach ($carriers as $c)<option value="{{ $c }}">@endforeach</datalist>
    <div class="grid grid-cols-2 gap-3">
        <x-ui.field name="distance_km" label="Км" inputmode="numeric" :value="$req->distance_km"/>
        <x-ui.field name="cost" label="₽" inputmode="numeric" :value="$req->cost ?? $towCost"/>
    </div>
</div>
<div class="mt-3 grid grid-cols-2 gap-3" data-reveal-target="pane" data-reveal-key="self" hidden>
    <x-ui.field name="planned_at" label="Когда привезёт" type="datetime-local" :value="$req->planned_at?->format('Y-m-d\TH:i')" disabled/>
    <x-ui.field name="yard_id" label="Куда" :options="$yards" placeholder="—" :value="$req->yard_id" disabled/>
</div>
<div class="mt-3" data-reveal-target="pane" data-reveal-key="missed" hidden>
    <x-ui.field name="next_call_at" label="Позвонить снова" type="datetime-local" :value="now()->addHours(2)->format('Y-m-d\TH:i')" disabled/>
</div>
<div class="mt-4"><x-ui.button id="act-submit" class="w-full sm:w-auto">Назначить эвакуатор</x-ui.button></div>
