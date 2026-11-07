{{-- Машина на экране «Парсинг» (08.11.2026) — одна плашка `.list`, как «Настройки» iOS: сверху строка-ссылка в главное
     место машины (сделка → предложение → ТС парковки) и строки других мест, ниже спорные поля — подпись поля и варианты
     строками выбора с кружком справа (у текущего — лаймовый с галкой). Нажатие на строку пишет вариант, на текущую —
     «оставить» (`car_variants_controller`, `POST /settings/parsing/cars/{car}/fields/{field}`), фрейм перечитывается сам.
     Откуда вариант — тусклой строкой под значением, открыть источник — значком справа (кнопкой: в кнопке выбора ссылок быть не может).
     Разобрано всё — одна строка «разобрано». Строй один на телефоне и ПК. --}}
@php
    use App\Support\Surface;
    $car = $item['car'];
    $variants = $item['variants'];
    $offer = $car->offer;
    $vehicle = $car->vehicle;
    $deal = $offer?->deal;
    $request = $vehicle?->requests->first(fn ($r) => $r->isOpen());
    $doc = \App\Cars\Sources\SourceDocs::for($variants, '/work/mail');
    $only ??= null;
    // Места машины: первое — шапка карточки, остальные — строками под ней.
    // Справа у места — его состояние одним словом; у предложения при сделке — без него (вкладка «Сделки» ничего не добавит).
    $places = array_values(array_filter([
        $deal ? ['Сделка', '', Surface::Crm->url('/work/deals/'.$deal->id), 'deal'] : null,
        $offer ? ['Предложение', $deal ? '' : $offer->crmTab(), Surface::Crm->url('/offers/'.$offer->number), 'grid'] : null,
        $vehicle ? ['Парковка'.($vehicle->yard ? ' · '.$vehicle->yard->name : ''), $vehicle->state->label(), Surface::Park->url('/cars/'.$vehicle->id), 'park'] : null,
        $request ? ['Заявка · '.$request->type->label(), '', Surface::Park->url('/requests/'.$request->id), 'park'] : null,
    ]));
    $main = array_shift($places);
    $title = $car->titleWithYear() ?: 'Без марки';
@endphp
<turbo-frame id="parsing-car-{{ $car->id }}" data-reload-src="/settings/parsing/cars/{{ $car->id }}">
    @if ($variants)
        <div class="list parsing-car" data-controller="car-variants" data-car-variants-url-value="/settings/parsing/cars/{{ $car->id }}/fields">
            <a @if ($main) href="{{ $main[2] }}" target="_top" data-turbo-frame="_top" @endif class="row">
                <span class="row-icon row-icon-s row-icon-plain"><x-ui.cat-icon :category="$offer?->category()" class="size-5"/></span>
                <span class="min-w-0 flex-1">
                    <span class="cell-title block truncate">{{ $title }}</span>
                    <span class="row-sub">
                        <x-vendor.ref :vendor="$offer?->vendor ?? $vehicle?->vendor" :ref="$offer?->claim_ref ?? $vehicle?->ref"/>
                        @if ($car->plate)<span class="nums">{{ $car->plate }}</span>@endif
                    </span>
                </span>
                @if ($main)
                    <span class="parsing-place">{{ $main[1] !== '' ? $main[1] : $main[0] }}</span>
                    <x-ui.icon name="chevron-right" class="size-4 shrink-0 text-ink-dim"/>
                @endif
            </a>
            @foreach ($places as [$label, $state, $href, $icon])
                <a href="{{ $href }}" target="_top" data-turbo-frame="_top" class="row">
                    <x-ui.row-icon :name="$icon" size="s"/>
                    <span class="min-w-0 flex-1 truncate">{{ $label }}</span>
                    @if ($state !== '')<span class="parsing-place text-ink-dim">{{ $state }}</span>@endif
                    <x-ui.icon name="chevron-right" class="size-4 shrink-0 text-ink-dim"/>
                </a>
            @endforeach
            @foreach ($variants as $field => $v)
                @php $other = $only && $only !== $field; @endphp
                    <div @class(['parsing-head', 'parsing-other' => $other])>{{ $v['label'] }}</div>
                    @unless ($v['current'])
                        <div @class(['row parsing-choice parsing-choice--empty', 'parsing-other' => $other])>
                            <span class="min-w-0 flex-1 py-2.5"><span class="parsing-value text-ink-dim">—</span><span class="parsing-source">сейчас пусто</span></span>
                            <span class="parsing-radio-room"></span>
                        </div>
                    @endunless
                    @foreach (array_filter([$v['current'] ? $v['current'] + ['now' => true] : null, ...$v['options']]) as $o)
                        @php
                            $now = ! empty($o['now']);
                            $open = collect($o['sources'])->map(fn ($s) => $s['kind'] === 'migtorg' ? ['lot', 'https://www.migtorg.com/lots/'.$s['id']] : (($d = $doc($s)) ? ['doc', $d] : null))->filter()->first();
                            $from = $o['sources'] ? implode(', ', array_column($o['sources'], 'title')) : 'сейчас в карточке';
                        @endphp
                        <div @class(['row parsing-choice', 'is-now' => $now, 'parsing-other' => $other])>
                            <button type="button" class="parsing-pick" title="{{ $now ? 'Оставить' : 'Выбрать' }}"
                                data-action="car-variants#{{ $now ? 'keep' : 'pick' }}" data-car-variants-field-param="{{ $field }}"
                                @unless ($now) data-car-variants-key-param="{{ $o['key'] }}" @endunless data-car-variants-seen-param="{{ $v['current']['key'] ?? '' }}">
                                <span class="min-w-0 flex-1">
                                    <span class="parsing-value">{{ $o['label'] }}</span>
                                    <span class="parsing-source">{{ $from }}</span>
                                </span>
                            </button>
                            @if ($open && $open[0] === 'lot')
                                <a href="{{ $open[1] }}" target="_blank" rel="noopener" class="parsing-open" aria-label="Открыть лот" title="Открыть лот"><x-ui.icon name="share" class="size-4"/></a>
                            @elseif ($open)
                                <x-ui.doc :doc="$open[1]" class="parsing-open" aria-label="Открыть документ"><x-ui.icon name="file" class="size-4"/></x-ui.doc>
                            @else
                                <span class="parsing-open"></span>
                            @endif
                            <span class="parsing-radio">@if ($now)<x-ui.icon name="check" class="size-3.5"/>@endif</span>
                        </div>
                    @endforeach
            @endforeach
        </div>
    @else
        <div class="list"><div class="row text-ink-dim"><x-ui.row-icon name="check" tone="soft" size="s"/><span class="min-w-0 flex-1 truncate">{{ $title }} — разобрано</span></div></div>
    @endif
</turbo-frame>
