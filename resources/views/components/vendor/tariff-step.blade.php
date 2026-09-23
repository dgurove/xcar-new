{{-- Ступень прайса строкой внутри лестницы: цена, от какой заявленной стоимости и с каких суток она
     действует. Пустая цена — ступени больше нет (та же кнопка «Убрать» просто снимает строку). --}}
@php $n = 'rows['.$i.']'; @endphp
<div class="flex flex-wrap items-end gap-3" data-row>
    <input type="hidden" name="{{ $n }}[id]" value="{{ $t?->id }}">
    <x-ui.field :name="$n.'[price]'" label="Цена" :value="$t?->price !== null ? rtrim(rtrim(number_format($t->price, 2, '.', ''), '0'), '.') : null" class="w-24" inputmode="decimal"/>
    <x-ui.field :name="$n.'[from_value]'" label="От стоимости" :value="$t?->from_value" class="w-36" inputmode="numeric" data-tariff-form-target="value"/>
    <x-ui.field :name="$n.'[from_day]'" label="С суток" :value="$t?->from_day ?? 1" class="w-24" inputmode="numeric" data-tariff-form-target="tier"/>
    <x-ui.field :name="$n.'[km_included]'" label="Км в фиксе" :value="$t?->km_included" class="w-28" inputmode="numeric" data-tariff-form-target="km"/>
    <div class="pb-2.5"><x-ui.check :name="$n.'[vat]'" :checked="$t?->vat ?? false">С НДС</x-ui.check></div>
    <button type="button" class="btn btn-s btn-quiet btn-round" data-action="repeater#remove" aria-label="Убрать ступень"><x-ui.icon name="x" class="size-4"/></button>
</div>
