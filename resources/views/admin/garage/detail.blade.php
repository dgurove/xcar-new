{{-- Карточка машины в «Работе → Гараже»: кадр, название, этап и чья; действие — карточка машины на сайте (там её ведут),
     у ждущей — ещё сделка в CRM; ниже путь по этапам, деньги (в CRM — с закупочной) и расходы строками. --}}
@php
    use App\Garage\Payer;
    use App\Support\Money;
    $offer = $car->offer;
    $staff = true;
    $invoice = $car->invoice;
    $current = $car->payoutInvoice ?? $invoice;
    $unpaid = $current && $current->remaining() > 0;
    $s = \App\Garage\Settlement::of($car);
    $asks = false;
    $waitingBlock = $offer->stage()?->block?->name;
@endphp
<x-ui.detail>
    <x-ui.row-card :href="$car->url()" :title="$offer->titleWithYear()" :photo="$offer->mainPhoto()">
        <x-slot:marks>
            <x-ui.state :tone="$car->state->tone()">{{ mb_strtolower($car->state->label()) }}</x-ui.state>
            @if ($car->manager)<x-ui.person :user="$car->manager"/>@else<span class="tag">взяли под себя</span>@endif
            @if ($car->deal?->garage_payer)<span class="tag">поставщику платит {{ mb_strtolower($car->deal->garage_payer->label()) }}</span>@endif
        </x-slot:marks>
        <x-slot:actions>
            <a href="{{ $car->url() }}" class="btn btn-s btn-accent" data-turbo="false">Открыть машину</a>
            @if ($car->isWaiting() && $car->deal_id)<a href="/work/deals/{{ $car->deal_id }}" class="btn btn-s btn-quiet">Сделка</a>@endif
        </x-slot:actions>
    </x-ui.row-card>
    <div class="mt-5 flex flex-col gap-5">
        @include('garage.cars.path')
        @unless ($car->isWaiting())@include('garage.cars.money')@endunless
        @if ($car->costs->isNotEmpty())
            <section>
                <h3 class="list-head pt-0">Расходы<span class="nums ml-auto text-base font-normal text-ink-muted">{{ Money::exact($car->spent()) }}</span></h3>
                <div class="list">
                    @foreach ($car->costs as $cost)
                        <div class="row">
                            <span class="min-w-0 flex-1"><span class="block">{{ $cost->title }}</span><span class="row-sub">{{ $cost->spent_at->translatedFormat('j F') }}@if ($cost->payer === Payer::Xcar), платили мы@endif</span></span>
                            <span class="nums shrink-0">{{ Money::exact($cost->amount) }}</span>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif
    </div>
</x-ui.detail>
