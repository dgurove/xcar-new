{{-- «Характеристики» ТС коробкой: витрина, гараж, вывоз, сделка. full — VIN целиком с копированием и адрес осмотра. --}}
@props(['offer', 'full' => false])
@php $facts = \App\Offers\OfferFacts::for($offer, $full); @endphp
@if ($facts)
    <section {{ $attributes->merge(['class' => 'box']) }}>
        <h2 class="box-title">Характеристики</h2>
        <dl class="mt-3 grid grid-cols-2 gap-x-6 gap-y-3">
            @foreach ($facts as $label => $value)
                <div @class(['min-w-0', 'col-span-2' => in_array($label, \App\Offers\OfferFacts::WIDE, true)])>
                    <dt class="text-sm text-ink-dim">{{ $label }}</dt>
                    <dd @class(['mt-0.5 font-medium', 'nums whitespace-nowrap' => $label === 'VIN', 'break-words' => $label !== 'VIN'])>
                        @if ($label === 'VIN')<x-ui.vin-code :vin="$value" :copy="$full || $offer->show_vin"/>
                        @elseif (in_array($label, ['Город', 'Осмотр'], true))<x-ui.place>{{ $value }}</x-ui.place>
                        @else{{ $value }}@endif
                    </dd>
                </div>
            @endforeach
        </dl>
    </section>
@endif
