{{-- «История» окна «Оценить из текста» (17.11.2026, владелец: «компактный список с лого вендора, кол-вом машин, отметками —
     оценочные или закупочные, с наценкой или без — и с датой, временем»). Строка — одно сохранение окна или бота
     (`ValuationRun`): логотипы вендоров, «9 ТС», что за суммы; справа дата и кто. Нажатие разворачивает машины: номер убытка
     с логотипом и сумма из текста, номер ведёт на страницу машины. --}}
@php use App\Support\Money; use App\Support\Ago; @endphp
<turbo-frame id="valuation-history">
    @if ($runs->isNotEmpty())
        <h3 class="list-head valuation-history-head">История</h3>
        <div class="list valuation-history">
            @foreach ($runs as $run)
                <details class="valuation-run">
                    <summary class="row">
                        <span class="valuation-run-logos">
                            @foreach (array_keys($run->vendors) as $vid)
                                @if ($vendors->has($vid))<x-vendor.logo :vendor="$vendors[$vid]"/>@endif
                            @endforeach
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="block">{{ $run->cars }} ТС</span>
                            <span class="row-sub">
                                <span @class(['valuation-run-mode', 'is-floor' => ! $run->markup])>{{ $run->markup ? 'оценочные, с наценкой' : 'закупочные, без наценки' }}</span>
                                @if ($run->source !== 'text')<span>{{ \App\Offers\ValuationRun::SOURCES[$run->source] ?? '' }}</span>@endif
                            </span>
                        </span>
                        <span class="valuation-run-when">
                            <span class="block">{{ Ago::html($run->created_at, true) }}</span>
                            @if ($run->user)<span class="row-sub">{{ $run->user->shortName() }}</span>@endif
                        </span>
                    </summary>
                    <div class="valuation-run-items">
                        @foreach ($run->items as $item)
                            <a href="/offers/{{ $item['number'] }}" class="valuation-run-item" data-turbo-frame="_top">
                                <x-vendor.ref :vendor-id="$item['vendor_id'] ?? null" :ref="$item['ref'] ?: '№ '.$item['number']"/>
                                <span class="nums ml-auto">{{ Money::nums($item['amount']) }}</span>
                            </a>
                        @endforeach
                    </div>
                </details>
            @endforeach
        </div>
    @endif
</turbo-frame>
