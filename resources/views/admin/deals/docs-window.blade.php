{{-- Окно сделки модератора (14.11.2026, владелец: «админам и модераторам вручную менять сумму договора и счёта»; модератору
     открыли «Сделки»): только документы — машина, менеджер, кто продаёт и НДС словом, сумма договора (правит) и ДКП (данные
     собственника, покупатель, дата). Окна машины (`admin/car/window`) с полями ТС, путём и деньгами у модератора нет: там
     сплошь действия админа. Страницы у окна нет. --}}
@php
    $offer = $deal->offer;
    $line = \App\Offers\DealLine::of($deal);
@endphp
<x-ui.detail>
    <x-ui.row-card stack :title="$offer->titleWithYear()" :photos="$offer->visiblePhotos()">
        <x-slot:status><span class="state {{ $line->tone }}">{{ $line->word }}</span></x-slot:status>
        <x-slot:marks><x-car.codes :offer="$offer"/></x-slot:marks>
        @if ($deal->amount)
            <x-slot:aside><div class="nums whitespace-nowrap text-lg font-semibold">{{ \App\Support\Money::rub($deal->amount) }}</div></x-slot:aside>
        @endif
        <div class="flex flex-col gap-4">
            @if ($deal->buyer)
                <div class="list"><div class="row"><span class="shrink-0 text-ink-muted">Менеджер</span><span class="min-w-0 flex-1 text-right"><x-ui.person :user="$deal->buyer" full/></span></div></div>
            @endif
            <x-deal.scheme :deal="$deal"/>
            @if ($deal->hasContract())<x-deal.contract :deal="$deal"/>@endif
        </div>
    </x-ui.row-card>
</x-ui.detail>
