{{-- «Срок обязывающего предложения» — одно поле на «Показ» и «Вернуть в продажу» (12.11.2026, владелец: «поля по-разному сделаны,
     хотя смысл одинаковый», «срок обязывающего тоже»; в публикации сделки для других — не нужен). Данность от страховой: до когда поставщик держит
     закупочную, приём подтверждений — не позже. Только вендор по комиссии (Совкомбанк, `Binding::applies`); в «Показе» вендор
     меняется на лету — `by-vendor` прячет поле. --}}
@props(['offer', 'id', 'form' => null, 'span' => null])
@php $bindingDays = $offer->vendorBindingDays(); @endphp
<div @class([$span]) data-controller="by-vendor" data-by-vendor-ids-value="{{ json_encode(\App\Offers\Binding::vendorIds()) }}" @unless (\App\Offers\Binding::applies($offer)) hidden @endunless>
    <x-ui.field name="binding_until" :id="$id" label="Срок обязывающего предложения" type="datetime-local" default-time="23:59"
        :value="old('binding_until', $offer->binding_until?->format('Y-m-d\TH:i'))" :placeholder="$bindingDays ? 'по вендору '.$bindingDays.' дн' : null" :form="$form"/>
</div>
