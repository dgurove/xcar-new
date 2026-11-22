{{-- «Оплата сделки» страницей — шторка строки «Оплат» XCAR Pay (`OfferController::money`, 22.11.2026): название и коды, тело —
     `admin/car/money-body`, «Открыть сделку» — страница машины в той же шторке. --}}
@php [$statusWord, $statusTone] = $car->status(); @endphp
<x-ui.shell :title="$offer->titleWithYear()" copy-title cache="no-cache" narrow>
    <x-slot:badge><span class="title-state {{ $statusTone }}">{{ $statusWord }}</span></x-slot:badge>
    <div class="flex flex-col gap-4">
        <div class="flex flex-wrap items-center gap-1.5"><x-car.codes :offer="$offer"/></div>
        @include('admin.car.money-body')
        <a href="/offers/{{ $n }}" class="btn btn-quiet w-full">Открыть сделку<x-ui.chevron class="ml-1"/></a>
    </div>
</x-ui.shell>
