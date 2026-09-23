{{-- Лестница одной услуги целиком: ступени строками, «+ ступень» добавляет пустую, одна кнопка
     «Сохранить» на всю лестницу. Прайс из договора вводят сразу пятью ступенями, а не пятью
     открытиями шторки. Изменённая цена не правит старую строку, а закрывает её вчерашним днём:
     выставленные счета и прошлые дни считаются по той цене, что была тогда. --}}
@props(['service' => null, 'rows' => null, 'vendorId' => null, 'yardId' => null, 'catValue' => null])
@php
    use App\Vendors\TariffService;
    $rows = $rows ?? collect();
    $key = ($service?->value ?? 'new').'-'.($catValue ?? 'any');
@endphp
<form method="post" action="/settings/tariffs/ladder" class="box-nested flex flex-col gap-3" data-controller="tariff-form repeater">
    @csrf
    <input type="hidden" name="vendor_id" value="{{ $vendorId }}">
    <input type="hidden" name="yard_id" value="{{ $yardId }}">
    <input type="hidden" name="category" value="{{ $catValue }}">
    <input type="hidden" name="sheet" value="{{ $catValue ?? 'any' }}">
    @if ($service)
        <input type="hidden" name="service" value="{{ $service->value }}" data-tariff-form-target="service">
        <div class="text-sm font-medium">{{ $service->label() }}</div>
    @else
        <x-ui.field name="service" label="Услуга" :options="TariffService::options()" value="storage" :id="'svc-'.$key" data-tariff-form-target="service" data-action="change->tariff-form#sync" class="w-48"/>
    @endif
    <div class="flex flex-col gap-3" data-repeater-target="list">
        @foreach ($rows as $i => $t)@include('components.vendor.tariff-step', ['t' => $t, 'i' => $i])@endforeach
        @if ($rows->isEmpty())@include('components.vendor.tariff-step', ['t' => null, 'i' => 0])@endif
    </div>
    <template data-repeater-target="template">@include('components.vendor.tariff-step', ['t' => null, 'i' => '__i__'])</template>
    <div class="flex gap-2">
        <x-ui.button size="sm">Сохранить</x-ui.button>
        <x-ui.button size="sm" type="button" variant="ghost" data-action="repeater#add">Ступень</x-ui.button>
    </div>
</form>
