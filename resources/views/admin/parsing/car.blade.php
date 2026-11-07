{{-- Машина на экране «Парсинг»: название и коды, где она сейчас — ссылками (предложение, сделка, ТС парковки, открытая
     заявка), ниже спорные поля — подпись и варианты кнопками. Нажатие на вариант пишет его, на текущий — «оставить»
     (`car_variants_controller`, `POST /settings/parsing/cars/{car}/fields/{field}`); после — фрейм перечитывается сам.
     Под вариантом — откуда: Мигторг ссылкой на лот, письмо и документ — в шторке документов. Разобрано всё — «Разобрано». --}}
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
    $links = array_filter([
        // Вкладка предложения — словом, кроме сделки: о ней — своя ссылка рядом.
        $offer ? [$deal ? 'Предложение' : 'Предложение · '.$offer->crmTab(), Surface::Crm->url('/offers/'.$offer->number)] : null,
        $deal ? ['Сделка', Surface::Crm->url('/work/deals/'.$deal->id)] : null,
        $vehicle ? ['Парковка'.($vehicle->yard ? ' · '.$vehicle->yard->name : '').' · '.$vehicle->state->label(), Surface::Park->url('/cars/'.$vehicle->id)] : null,
        $request ? ['Заявка · '.$request->type->label(), Surface::Park->url('/requests/'.$request->id)] : null,
    ]);
@endphp
<turbo-frame id="parsing-car-{{ $car->id }}" data-reload-src="/settings/parsing/cars/{{ $car->id }}">
    @if ($variants)
        <section class="box parsing-car" data-controller="car-variants" data-car-variants-url-value="/settings/parsing/cars/{{ $car->id }}/fields">
            <div class="flex items-start gap-2.5">
                <x-ui.cat-icon :category="$offer?->category()" class="mt-0.5 shrink-0"/>
                <div class="min-w-0 flex-1">
                    <div class="cell-title truncate">{{ $car->titleWithYear() ?: 'Без марки' }}</div>
                    <div class="mt-1 flex flex-wrap items-center gap-1.5">
                        <x-vendor.ref :vendor="$offer?->vendor ?? $vehicle?->vendor" :ref="$offer?->claim_ref ?? $vehicle?->ref" copy/>
                        @if ($car->plate)<span class="tag nums">{{ $car->plate }}</span>@endif
                    </div>
                </div>
            </div>
            @if ($links)
                <div class="mt-3 flex flex-wrap gap-1.5">
                    @foreach ($links as [$label, $href])
                        <a href="{{ $href }}" target="_top" data-turbo-frame="_top" class="chip parsing-link">{{ $label }}<x-ui.icon name="chevron-right" class="size-3.5 shrink-0"/></a>
                    @endforeach
                </div>
            @endif
            <div class="parsing-fields">
                @foreach ($variants as $field => $v)
                    <div @class(['parsing-field', 'parsing-field--other' => $only && $only !== $field])>
                        <div class="parsing-label">{{ $v['label'] }}</div>
                        <div class="parsing-options">
                            @unless ($v['current'])
                                <div class="parsing-option"><span class="parsing-pick parsing-pick--empty">—</span><span class="parsing-sources"><span class="variant-source">сейчас пусто</span></span></div>
                            @endunless
                            @foreach (array_filter([$v['current'] ? $v['current'] + ['now' => true] : null, ...$v['options']]) as $o)
                                <div class="parsing-option">
                                    @if (! empty($o['now']))
                                        <button type="button" class="parsing-pick parsing-pick--now" data-action="car-variants#keep"
                                            data-car-variants-field-param="{{ $field }}" data-car-variants-seen-param="{{ $v['current']['key'] }}" title="Оставить">
                                            <span class="min-w-0 break-words">{{ $o['label'] }}</span><x-ui.icon name="check" class="size-4 shrink-0"/>
                                        </button>
                                    @else
                                        <button type="button" class="parsing-pick" data-action="car-variants#pick"
                                            data-car-variants-field-param="{{ $field }}" data-car-variants-key-param="{{ $o['key'] }}" data-car-variants-seen-param="{{ $v['current']['key'] ?? '' }}">
                                            <span class="min-w-0 break-words">{{ $o['label'] }}</span>
                                        </button>
                                    @endif
                                    <span class="parsing-sources">
                                        @forelse ($o['sources'] as $s)
                                            @if ($s['kind'] === 'migtorg')
                                                <a href="https://www.migtorg.com/lots/{{ $s['id'] }}" target="_blank" rel="noopener" class="variant-source">{{ $s['title'] }}</a>
                                            @elseif ($d = $doc($s))
                                                <x-ui.doc :doc="$d" class="variant-source">{{ $s['title'] }}</x-ui.doc>
                                            @else
                                                <span class="variant-source">{{ $s['title'] }}</span>
                                            @endif
                                        @empty
                                            <span class="variant-source">сейчас в карточке</span>
                                        @endforelse
                                    </span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        </section>
    @else
        <div class="box parsing-done"><x-ui.icon name="check" class="size-4"/>{{ $car->titleWithYear() }} — разобрано</div>
    @endif
</turbo-frame>
