{{-- Лестница одной услуги целиком: ступени строками, «Ступень» добавляет пустую, одна кнопка «Сохранить»
     и одна дата «Действует с» на всю лестницу. Прайс из договора вводят сразу всеми ступенями, а не пятью
     открытиями шторки. Изменённая цена не правит старую строку, а закрывает её днём раньше: выставленные
     счета и прошлые дни считаются по той цене, что была тогда. Без service — форма новой услуги, и в ней
     только те услуги, которых в этой ячейке ещё нет: иначе одна её строка стёрла бы готовую лестницу. --}}
@props(['service' => null, 'rows' => null, 'available' => [], 'vendorId' => null, 'yardId' => null, 'catValue' => null])
@php
    $rows = $rows ?? collect();
    $key = ($service?->value ?? 'new').'-'.($catValue ?? 'any');
    // Ключ ошибки — со своей категорией и услугой: в шторках их несколько, общий ключ показал бы её во всех.
    $error = 'ladder.'.($catValue ?? 'any').'.'.($service?->value ?? 'new');
@endphp
<form method="post" action="/tariffs/ladder" class="box-nested flex flex-col gap-3" data-controller="tariff-form repeater">
    @csrf
    <input type="hidden" name="vendor_id" value="{{ $vendorId }}">
    <input type="hidden" name="yard_id" value="{{ $yardId }}">
    <input type="hidden" name="category" value="{{ $catValue }}">
    <input type="hidden" name="sheet" value="{{ $catValue ?? 'any' }}">
    <input type="hidden" name="ladder" value="{{ $service?->value }}">
    <div class="flex flex-wrap items-end gap-3">
        @if ($service)
            <input type="hidden" name="service" value="{{ $service->value }}" data-tariff-form-target="service">
            <span class="pb-2.5 text-sm font-medium">{{ $service->label() }}</span>
        @else
            <x-ui.field name="service" label="Услуга" :options="$available" :value="array_key_first($available)" :id="'svc-'.$key" data-tariff-form-target="service" data-action="change->tariff-form#sync" class="w-44"/>
        @endif
        <x-ui.field name="valid_from" label="Действует с" type="date" :value="now()->toDateString()" :id="'vf-'.$key" class="w-40"/>
    </div>
    <div class="flex flex-col gap-3" data-repeater-target="list">
        @foreach ($rows as $i => $t)@include('components.vendor.tariff-step', ['t' => $t, 'i' => $i])@endforeach
        @if ($rows->isEmpty())@include('components.vendor.tariff-step', ['t' => null, 'i' => 0])@endif
    </div>
    <template data-repeater-target="template">@include('components.vendor.tariff-step', ['t' => null, 'i' => '__i__'])</template>
    @error($error)<p class="field-error">{{ $message }}</p>@enderror
    <div class="flex gap-2">
        <x-ui.button size="sm">Сохранить</x-ui.button>
        <x-ui.button size="sm" type="button" variant="ghost" data-action="repeater#add">Ступень</x-ui.button>
        @if ($service && $rows->isNotEmpty())
            {{-- Снять лестницу целиком — только этой кнопкой: пустая отправка цены не стирает. --}}
            <x-ui.button size="sm" variant="ghost" name="drop" value="1" class="ml-auto" data-turbo-confirm="Убрать цены «{{ mb_strtolower($service->label()) }}»?">Убрать</x-ui.button>
        @endif
    </div>
</form>
