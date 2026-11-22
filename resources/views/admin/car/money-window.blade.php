{{-- Окно «Оплата сделки» — строка «Оплат» CRM (`CarWindow::money`, 22.11.2026): шапка окна машины без фото и сведений о продаже
     (название, коды, слово этапа в полосе), тело — `admin/car/money-body`, подвалом «Открыть сделку» — страница `/offers/N` во
     весь экран (значка «Открыть страницу» в полосе нет: одно действие — одно место). Держит машину после действий
     (`data-detail-stay`, как окно сделки). --}}
@php [$statusWord, $statusTone] = $car->status(); @endphp
<x-ui.detail data-detail-stay>
    <x-ui.row-card stack :title="$offer->titleWithYear()" :photos="collect()" :blank="false">
        <x-slot:status><span class="state {{ $statusTone }}">{{ $statusWord }}</span></x-slot:status>
        <x-slot:marks><x-car.codes :offer="$offer"/></x-slot:marks>
        {{-- Основным слотом, во всю ширину (22.11.2026: в слоте `actions` — строке кнопок — «Продажа» сжималась по содержимому). --}}
        <x-slot:actions></x-slot:actions>
        <div class="mt-3 flex w-full flex-col gap-3">@include('admin.car.money-body')</div>
        <x-slot:foot>
            <div class="detail-action"><a href="/offers/{{ $n }}" class="btn btn-quiet w-full" data-turbo-frame="_top">Открыть сделку<x-ui.chevron class="ml-1"/></a></div>
        </x-slot:foot>
    </x-ui.row-card>
</x-ui.detail>
