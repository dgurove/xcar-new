{{-- Итог «Оценить → Из текста» (OfferValuationController::preview). Карточка изменений на машину (05.10.2026, владелец:
     «схалтурил»): шапка — тип ТС, название, логотип вендора с номером убытка и галка; ниже одной фразой только то, что
     меняется: оценочная, закупочная, VIN, город. Новое — лаймом, заменяемое — зачёркнутым. Спор по сумме — сегментом
     «Оставить / Перезаписать» в шапке: VIN и город ложатся в любом случае. Чего нет ни у предложения, ни в тексте — поле
     строкой под фразой. Группы (25.10.2026) — сегментом сверху, видна одна: «Заполним» (и споры), «Проверьте» (VIN
     другой, номер дважды), «Нет в CRM» (заведём черновик: с лотом Мигторга — его поля и фото, без лота — по номеру, лот
     подтянется, когда появится; 26.10.2026), «Уже так». data-v / data-f — числа, которые valuation_controller прогоняет
     счётчиком. --}}
@php
    use App\Offers\Pricing;
    use App\Support\Money;
    $all = collect($items);
    $groups = collect([
        'fill' => ['Заполним', $all->whereIn('status', ['conflict', 'fill'])->sortBy(fn ($i) => ($i['status'] === 'conflict' || $i['gaps']) ? 0 : 1)->values()],
        'check' => ['Проверьте', $all->whereIn('status', ['check', 'dispute'])->values()],
        'absent' => ['Нет в CRM', $all->whereIn('status', ['absent', 'missing'])->values()],
        'same' => ['Уже так', $all->where('status', 'same')->values()],
    ])->filter(fn ($g) => $g[1]->isNotEmpty());
    $first = $groups->keys()->first();
@endphp
@if ($groups->count() > 1)
    <div class="segment valuation-tabs" role="tablist">
        @foreach ($groups as $key => [$title, $rows])
            <button type="button" role="tab" data-group="{{ $key }}" @if ($key === $first) aria-selected="true" @endif data-action="valuation#tab">{{ $title }} <span class="nums">{{ $rows->count() }}</span></button>
        @endforeach
    </div>
@endif
@foreach ($groups as $key => [$title, $rows])
    <section class="valuation-group" data-group="{{ $key }}" @if ($key !== $first) hidden @endif>
        @if ($groups->count() === 1)<h3 class="list-head">{{ $title }} <span class="nums text-ink-dim">{{ $rows->count() }}</span></h3>@endif
        @if ($key === 'fill' && $rows->where('status', 'conflict')->count() > 1)
            <button type="button" class="valuation-all" data-action="valuation#overwriteAll">Перезаписать все суммы текстом</button>
        @endif
        <div class="list">
            @foreach ($rows as $item)
                @php
                    $o = $item['offer'];
                    $status = $item['status'];
                @endphp
                {{-- В CRM нет: черновик с закупочной из текста. Что будет с полями и фото — фразой: лот живой, снят или его нет. --}}
                @if (in_array($status, ['absent', 'missing'], true))
                    @php
                        $lot = $item['lot'];
                        $what = match (true) {
                            (bool) $item['archived'] => 'вернём из архива',
                            ! $lot => 'новый черновик, на Мигторге пока нет',
                            (bool) $lot->gone_at => 'новый черновик, на Мигторге снят с торгов',
                            default => 'новый черновик с Мигторга',
                        };
                    @endphp
                    <div class="valuation-card valuation-row" data-valuation-row @if ($status === 'absent') data-create @endif data-floor="{{ $item['floor'] }}">
                        <div class="valuation-card-head">
                            <x-ui.cat-icon :category="\App\Cars\Category::Passenger" class="valuation-cat"/>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate">{{ $lot?->title ?: $item['ref'] }}</span>
                                <span class="row-sub"><x-vendor.ref :vendor-id="$item['vendor']" :ref="$item['ref']"/>@if ($lot?->city)<span>{{ $lot->city }}</span>@endif</span>
                            </span>
                            @if ($status === 'absent')<label class="check shrink-0" aria-label="Завести"><input type="checkbox" name="create[]" value="{{ $item['key'] }}" checked data-action="valuation#count"></label>@endif
                        </div>
                        <p class="valuation-facts">
                            @if ($status === 'missing')
                                <span class="text-urgent">номер в тексте дважды с разными суммами</span>
                            @else
                                <span>{{ $what }}</span>
                                <span>закупочная <span class="valuation-new valuation-floor nums" data-f>{{ Money::rub($item['floor']) }}</span></span>
                            @endif
                        </p>
                    </div>
                    @continue
                @endif
                {{-- «Уже так» — строкой: менять там нечего. --}}
                @if ($key === 'same' || ! $o)
                    <div class="row valuation-row" data-valuation-row data-value="{{ $item['amount'] }}" data-floor="{{ $item['floor'] }}">
                        @if ($o)<x-ui.cat-icon :category="$o->category()" class="valuation-cat"/>@endif
                        <span class="min-w-0 flex-1">
                            <span class="block truncate">{{ $o?->titleWithYear() ?? $item['ref'] }}</span>
                            @if ($o)<span class="row-sub"><x-vendor.ref :vendor-id="$o->vendor_id" :ref="$item['ref']"/></span>@endif
                        </span>
                        <span class="nums text-sm text-ink-muted" data-v>{{ Money::nums($item['amount']) }}</span>
                    </div>
                    @continue
                @endif
                @php
                    $conflict = $status === 'conflict';
                    $take = in_array($status, ['fill', 'check'], true);
                    // Не Альфа — сумма и есть закупочная, оценочной нет.
                    $valueChanges = ! $item['direct'] && (int) $o->value !== (int) $item['amount'];
                    $floorChanges = (int) $o->floor_price !== (int) $item['floor'];
                    // Спорное поле — зачёркнуто прежнее, решение — переключателем в шапке.
                    $disputeValue = $conflict && $item['value_differs'];
                    $disputeFloor = $conflict && ($item['value_differs'] || $item['floor_differs']);
                    $vinGap = ! ($item['vin_taken'] ?? null) && $status !== 'check' && ! $item['vin_fill'] && in_array('vin', $item['gaps'], true);
                    $cityGap = $status !== 'check' && ! $item['city_fill'] && in_array('city', $item['gaps'], true);
                @endphp
                <div @class(['valuation-card valuation-row', 'is-keep' => $conflict]) data-valuation-row data-value="{{ $item['amount'] }}" data-floor="{{ $item['floor'] }}"
                     @if ($conflict) data-conflict @if ($item['value_differs']) data-value-differs @endif @endif
                     @if ($item['vin_fill']) data-vin-fill @endif @if ($item['city_fill']) data-city-fill @endif
                     @if ($valueChanges || $floorChanges) data-price-change @endif>
                    <div class="valuation-card-head">
                        <x-ui.cat-icon :category="$o->category()" class="valuation-cat"/>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate">{{ $o->titleWithYear() }}</span>
                            <span class="row-sub"><x-vendor.ref :vendor-id="$o->vendor_id" :ref="$item['ref']"/>@if ($status === 'dispute')<span class="text-urgent">номер в тексте дважды</span>@endif</span>
                        </span>
                        @if ($take)<label class="check shrink-0" aria-label="Взять"><input type="checkbox" name="offers[]" value="{{ $o->id }}" @checked($status === 'fill') data-action="valuation#count"></label>@endif
                        @if ($conflict)<input type="hidden" name="offers[]" value="{{ $o->id }}">@include('admin.offers.valuation-keep', ['offer' => $o])@endif
                    </div>
                    <p class="valuation-facts">
                        @if ($valueChanges && $status !== 'dispute')
                            <span @if ($disputeValue) data-disputed @endif>Оценочная @if ($o->value)<s class="valuation-old nums">{{ Money::nums($o->value) }}</s> @endif<span class="valuation-new nums" data-v>{{ Money::nums($item['amount']) }}</span></span>
                        @endif
                        @if ($floorChanges && $status !== 'dispute')
                            <span @if ($disputeFloor) data-disputed @endif title="{{ Pricing::floorLabel((int) $item['floor'], $item['direct'] ? null : (int) $item['amount']) }}">закупочная @if ($o->floor_price)<s class="valuation-old nums">{{ Money::nums($o->floor_price) }}</s> @endif<span class="valuation-new valuation-floor nums" data-f>{{ Money::rub($item['floor']) }}</span></span>
                        @endif
                        @if ($item['ref_differs'] ?? null)
                            <span class="text-urgent">в тексте <span class="nums">{{ $item['ref'] }}</span>, в CRM <span class="nums">{{ $item['ref_differs'] }}</span></span>
                        @endif
                        @if ($item['vin_taken'] ?? null)
                            <span class="text-urgent">VIN <span class="nums">{{ $item['vin'] }}</span> уже у {{ \App\Cars\Identity::offerName($item['vin_taken']) }}</span>
                        @elseif ($status === 'check' && ! ($item['ref_differs'] ?? null))
                            <span class="text-urgent">VIN в CRM <span class="nums">{{ $o->vin }}</span>, в тексте <span class="nums">{{ $item['vin'] }}</span></span>
                        @elseif ($item['vin_fill'])
                            <span>VIN <span class="valuation-new nums">{{ $item['vin_fill'] }}</span></span>
                        @endif
                        @if ($status !== 'check' && ($c = $item['city_fill']))
                            <span>город @if ($c['from'])<s class="valuation-old">{{ $c['from'] }}</s> @endif<span class="valuation-new">{{ $c['title'] }}</span></span>
                        @endif
                    </p>
                    @if ($vinGap || $cityGap)
                        <div class="valuation-gaps">
                            @if ($vinGap)
                                <input type="text" name="vin[{{ $o->id }}]" maxlength="17" class="field-input field-s valuation-input nums uppercase" placeholder="Нет VIN, впишите"
                                       autocapitalize="characters" autocomplete="off" autocorrect="off" spellcheck="false" data-action="input->valuation#count">
                            @endif
                            @if ($cityGap)
                                <div class="valuation-input" data-action="change->valuation#count"><x-ui.combobox name="city[{{ $o->id }}]" label="" url="/reference/settlements" placeholder="Нет города, впишите"/></div>
                            @endif
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    </section>
@endforeach
