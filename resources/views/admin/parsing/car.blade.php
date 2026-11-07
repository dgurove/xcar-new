{{-- Машина на экране «Парсинг» (08.11.2026) — компактно (владелец: «по одной тачке на экран — пипец», «полосы не нужны»):
     шапка одной строкой — тип, название, коды, справа места машины ссылками (сделка, предложение, ТС и заявка парковки);
     ниже строка на спорное поле — подпись и варианты ячейками одинаковой ширины (колонки ровные от машины к машине). Ячейка
     — значение, под ним откуда; у текущей — лаймовая заливка с галкой. Нажатие на ячейку пишет вариант, на текущую —
     «оставить» (`car_variants_controller`, `POST /settings/parsing/cars/{car}/fields/{field}`), фрейм перечитывается сам.
     Источник открыть — значок в углу ячейки (лот — ссылкой, документ и письмо — в шторке документов). Фильтр по полю —
     только это поле. Разобрано всё — одна строка «разобрано». --}}
@php
    use App\Support\Surface;
    $car = $item['car'];
    $only ??= null;
    $variants = $only ? array_intersect_key($item['variants'], [$only => true]) : $item['variants'];
    $offer = $car->offer;
    $vehicle = $car->vehicle;
    $request = $vehicle?->requests->first(fn ($r) => $r->isOpen());
    $doc = \App\Cars\Sources\SourceDocs::for($variants, '/work/mail');
    $places = array_filter([
        // Предложение и сделка — одна машина: одна ссылка, словом «Сделка» / «Гараж» / «Предложение».
        ...($offer ? [($offer->dealNow() || $offer->state === \App\Offers\OfferState::Garage ? $offer->crmPlace() : 'Предложение') => $offer->crmPath()] : []),
        'Парковка' => $vehicle ? Surface::Park->url('/cars/'.$vehicle->id) : null,
        'Заявка' => $request ? Surface::Park->url('/requests/'.$request->id) : null,
    ]);
    $title = $car->titleWithYear() ?: 'Без марки';
@endphp
<turbo-frame id="parsing-car-{{ $car->id }}" data-reload-src="/settings/parsing/cars/{{ $car->id }}{{ $only ? '?field='.$only : '' }}">
    @if ($variants)
        <section class="box parsing-car" data-controller="car-variants" data-car-variants-url-value="/settings/parsing/cars/{{ $car->id }}/fields">
            <div class="parsing-top">
                <x-ui.cat-icon :category="$offer?->category()" class="size-5 shrink-0"/>
                <span class="min-w-0 truncate font-medium">{{ $title }}</span>
                <span class="parsing-meta">
                <span class="parsing-codes">
                    <x-vendor.ref :vendor="$offer?->vendor ?? $vehicle?->vendor" :ref="$offer?->claim_ref ?? $vehicle?->ref"/>
                    @if ($car->plate)<span class="nums">{{ $car->plate }}</span>@endif
                </span>
                <span class="parsing-places">
                    @foreach ($places as $label => $href)
                        <a href="{{ $href }}" target="_top" data-turbo-frame="_top">{{ $label }}<x-ui.icon name="chevron-right" class="size-3.5"/></a>
                    @endforeach
                </span>
                </span>
            </div>
            @foreach ($variants as $field => $v)
                <div class="parsing-row">
                    <div class="parsing-label">{{ $v['label'] }}</div>
                    <div class="parsing-cells">
                        @unless ($v['current'])
                            <div class="parsing-cell is-empty"><span class="parsing-value">—</span><span class="parsing-from">пусто</span></div>
                        @endunless
                        @foreach (array_filter([$v['current'] ? $v['current'] + ['now' => true] : null, ...$v['options']]) as $o)
                            @php
                                $now = ! empty($o['now']);
                                $open = collect($o['sources'])->map(fn ($s) => $s['kind'] === 'migtorg' ? ['link', 'https://www.migtorg.com/lots/'.$s['id']] : (($d = $doc($s)) ? [$s['kind'] === 'letter' ? 'mail' : 'file', $d] : null))->filter()->first();
                            @endphp
                            <div @class(['parsing-cell', 'is-now' => $now])>
                                <button type="button" class="parsing-pick" title="{{ $now ? 'Оставить' : 'Выбрать' }}"
                                    data-action="car-variants#{{ $now ? 'keep' : 'pick' }}" data-car-variants-field-param="{{ $field }}"
                                    @unless ($now) data-car-variants-key-param="{{ $o['key'] }}" @endunless data-car-variants-seen-param="{{ $v['current']['key'] ?? '' }}">
                                    <span class="parsing-value">@if ($now)<x-ui.icon name="check" class="parsing-check size-4"/>@endif{{ $o['label'] }}</span>
                                    {{-- Коротко, ячейка узкая: «Мигторг 815720», у текущего без источника — «сейчас». --}}
                                    <span class="parsing-from">{{ $o['sources'] ? str_replace('Мигторг, лот ', 'Мигторг ', implode(', ', array_column($o['sources'], 'title'))) : 'сейчас' }}</span>
                                </button>
                                @if ($open && $open[0] === 'link')
                                    <a href="{{ $open[1] }}" target="_blank" rel="noopener" class="parsing-open" aria-label="Лот на Мигторге" title="Лот на Мигторге"><x-ui.icon name="link" class="size-4"/></a>
                                @elseif ($open)
                                    <x-ui.doc :doc="$open[1]" class="parsing-open" aria-label="Открыть"><x-ui.icon :name="$open[0]" class="size-4"/></x-ui.doc>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </section>
    @else
        <div class="box parsing-car parsing-done"><x-ui.icon name="check" class="size-4 shrink-0"/><span class="min-w-0 truncate">{{ $title }} — разобрано</span></div>
    @endif
</turbo-frame>
