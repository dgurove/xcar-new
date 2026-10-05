{{-- Итог «Оценить → Из текста» (OfferValuationController::preview). Карточка изменений на машину (05.10.2026, владелец:
     «схалтурил»): шапка — ТС и номер убытка с галкой, ниже строки «поле — было → станет» только того, что меняется:
     оценочная, закупочная, VIN, город. Новое — лаймом, заменяемое — зачёркнутым. Спор по сумме — сегментом «Оставить /
     Перезаписать» в строке суммы, а не на всю машину: VIN и город ложатся в любом случае. Чего нет ни у предложения, ни
     в тексте — поле прямо в строке. Группы: «Заполним» (и споры), «Проверьте» (VIN другой, номер дважды), свёрнутые
     «Уже так» и «Нет в CRM». data-v / data-f — числа, которые valuation_controller прогоняет счётчиком. --}}
@php
    use App\Support\Money;
    $all = collect($items);
    $groups = [
        'fill' => ['Заполним', $all->whereIn('status', ['conflict', 'fill'])->sortBy(fn ($i) => ($i['status'] === 'conflict' || $i['gaps']) ? 0 : 1)->values(), false],
        'check' => ['Проверьте', $all->whereIn('status', ['check', 'dispute'])->values(), false],
        'same' => ['Уже так', $all->where('status', 'same')->values(), true],
        'missing' => ['Нет в CRM', $all->where('status', 'missing')->values(), true],
    ];
@endphp
@foreach ($groups as $key => [$title, $rows, $folded])
    @continue($rows->isEmpty())
    <details class="valuation-group" data-status="{{ $key }}" @unless ($folded) open @endunless>
        <summary class="list-head"><span>{{ $title }} <span class="nums text-ink-dim">{{ $rows->count() }}</span></span>
            @if ($key === 'fill' && $rows->where('status', 'conflict')->count() > 1)<button type="button" class="facet-all ml-auto !py-0" data-action="valuation#overwriteAll">Перезаписать все суммы</button>@endif
        </summary>
        <div class="list mb-3">
            @foreach ($rows as $item)
                @php
                    $o = $item['offer'];
                    $status = $item['status'];
                @endphp
                {{-- Свёрнутые группы и «нет в CRM» — строкой: менять там нечего. --}}
                @if ($folded || ! $o)
                    <div class="row valuation-row" data-valuation-row data-value="{{ $item['amount'] }}" data-floor="{{ $item['floor'] }}">
                        <span class="min-w-0 flex-1"><span class="block truncate">{{ $o?->titleWithYear() ?? $item['ref'] }}</span>@if ($o)<span class="row-sub nums">{{ $item['ref'] }}</span>@endif</span>
                        <span class="nums text-sm text-ink-muted" data-v>{{ Money::nums($item['amount']) }}</span>
                    </div>
                    @continue
                @endif
                @php
                    $conflict = $status === 'conflict';
                    $take = in_array($status, ['fill', 'check'], true);
                    $valueChanges = (int) $o->value !== (int) $item['amount'];
                    $floorChanges = (int) $o->floor_price !== (int) $item['floor'];
                    // Спорное поле — зачёркнуто прежнее, решение — переключателем в шапке.
                    $disputeValue = $conflict && $item['value_differs'];
                    $disputeFloor = $conflict && ($item['value_differs'] || $item['floor_differs']);
                @endphp
                <div @class(['valuation-card valuation-row', 'is-keep' => $conflict]) data-valuation-row data-value="{{ $item['amount'] }}" data-floor="{{ $item['floor'] }}"
                     @if ($conflict) data-conflict @if ($item['value_differs']) data-value-differs @endif @endif
                     @if ($item['vin_fill']) data-vin-fill @endif @if ($item['city_fill']) data-city-fill @endif
                     @if ($valueChanges || $floorChanges) data-price-change @endif>
                    <div class="valuation-card-head">
                        <span class="min-w-0 flex-1">
                            <span class="block truncate">{{ $o->titleWithYear() }}</span>
                            <span class="block text-sm text-ink-muted"><span class="nums">{{ $item['ref'] }}</span>@if ($status === 'dispute')<span class="ml-2 text-urgent">номер в тексте дважды</span>@endif</span>
                        </span>
                        @if ($take)<label class="check shrink-0" aria-label="Взять"><input type="checkbox" name="offers[]" value="{{ $o->id }}" @checked($status === 'fill') data-action="valuation#count"></label>@endif
                        {{-- Спор по сумме — переключателем справа в шапке, на месте галки: в строке суммы он раздувал её
                             высоту, и между оценочной и закупочной вставала пустая строка (владелец 05.10.2026). --}}
                        @if ($conflict)<input type="hidden" name="offers[]" value="{{ $o->id }}">@include('admin.offers.valuation-keep', ['offer' => $o])@endif
                    </div>
                    <dl class="valuation-fields">
                        @if ($valueChanges && $status !== 'dispute')
                            <dt>Оценочная</dt>
                            <dd @if ($disputeValue) data-disputed @endif>
                                @if ($o->value)<s class="valuation-old nums">{{ Money::nums($o->value) }}</s>@endif
                                <span class="valuation-new nums" data-v>{{ Money::nums($item['amount']) }}</span>
                            </dd>
                        @endif
                        @if ($floorChanges && $status !== 'dispute')
                            <dt>Закупочная</dt>
                            <dd @if ($disputeFloor) data-disputed @endif>
                                @if ($o->floor_price)<s class="valuation-old nums">{{ Money::nums($o->floor_price) }}</s>@endif
                                <span class="valuation-new valuation-floor nums" data-f>{{ Money::rub($item['floor']) }}</span>
                            </dd>
                        @endif
                        @if ($status === 'check')
                            <dt>VIN</dt>
                            <dd class="text-urgent"><span class="nums">{{ $o->vin }}</span> в CRM, <span class="nums">{{ $item['vin'] }}</span> в тексте</dd>
                        @elseif ($item['vin_fill'])
                            <dt>VIN</dt><dd><span class="valuation-new nums">{{ $item['vin_fill'] }}</span></dd>
                        @elseif (in_array('vin', $item['gaps'], true))
                            <dt class="text-danger">VIN</dt>
                            <dd><input type="text" name="vin[{{ $o->id }}]" maxlength="17" class="field-input field-s valuation-input nums uppercase" placeholder="Впишите VIN"
                                       autocapitalize="characters" autocomplete="off" autocorrect="off" spellcheck="false" data-action="input->valuation#count"></dd>
                        @endif
                        @if ($status !== 'check')
                            @if ($c = $item['city_fill'])
                                <dt>Город</dt>
                                <dd>@if ($c['from'])<s class="valuation-old">{{ $c['from'] }}</s>@endif<span class="valuation-new">{{ $c['title'] }}</span></dd>
                            @elseif (in_array('city', $item['gaps'], true))
                                <dt class="text-danger">Город</dt>
                                <dd class="valuation-input" data-action="change->valuation#count"><x-ui.combobox name="city[{{ $o->id }}]" label="" url="/reference/settlements" placeholder="Впишите город"/></dd>
                            @endif
                        @endif
                    </dl>
                </div>
            @endforeach
        </div>
    </details>
@endforeach
