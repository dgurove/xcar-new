{{-- Итог «Оценить → Из текста» (OfferValuationController::preview): группы строк, у тех, что можно взять, — галка.
     data-value / data-floor — числа, которые valuation_controller прогоняет счётчиком. --}}
@php
    use App\Support\Money;
    $groups = collect($items)->groupBy('status');
    $titles = ['conflict' => 'Не совпадает с вписанным', 'fill' => 'Заполним', 'check' => 'Проверьте', 'dispute' => 'Разные суммы', 'same' => 'Уже так', 'missing' => 'Нет в CRM'];
@endphp
@foreach (\App\Offers\Actions\MatchValuations::ORDER as $status)
    @continue(! $groups->has($status))
    @php $rows = $groups[$status]; $folded = in_array($status, ['same', 'missing'], true); @endphp
    <details class="valuation-group" data-status="{{ $status }}" @unless ($folded) open @endunless>
        <summary class="list-head"><span>{{ $titles[$status] }} <span class="nums text-ink-dim">{{ $rows->count() }}</span></span>
            @if ($status === 'conflict' && $rows->count() > 1)<button type="button" class="facet-all ml-auto !py-0" data-action="valuation#overwriteAll">Перезаписать все</button>@endif
        </summary>
        <div class="list mb-3">
            @foreach ($rows as $item)
                @php $o = $item['offer']; $take = $o && in_array($status, ['fill', 'check'], true); @endphp
                {{-- Расхождение: что вписано и что в тексте, выбор «Оставить» (по умолчанию) или «Перезаписать». --}}
                @if ($status === 'conflict')
                    <div class="row valuation-row valuation-conflict" data-valuation-row data-value="{{ $item['amount'] }}" data-floor="{{ $item['floor'] }}">
                        <input type="hidden" name="offers[]" value="{{ $o->id }}">
                        <span class="min-w-0">
                            <span class="block truncate">{{ $o->titleWithYear() }}</span>
                            <span class="row-sub nums">{{ $item['ref'] }}</span>
                        </span>
                        <dl class="valuation-diff">
                            @if ($item['value_differs'])<dt>Оценочная</dt><dd><span class="nums">{{ Money::nums($o->value) }}</span> → <span class="nums text-urgent">{{ Money::nums($item['amount']) }}</span></dd>@endif
                            @if ($item['floor_differs'])<dt>Закупочная</dt><dd><span class="nums">{{ Money::nums($o->floor_price) }}</span> → <span class="nums text-urgent">{{ Money::nums($item['floor']) }}</span></dd>@endif
                        </dl>
                        <span class="segment valuation-choice">
                            <label><input type="radio" name="keep[{{ $o->id }}]" value="keep" checked data-action="valuation#count">Оставить</label>
                            <label><input type="radio" name="keep[{{ $o->id }}]" value="overwrite" data-action="valuation#count">Перезаписать</label>
                        </span>
                    </div>
                    @continue
                @endif
                <label @class(['row valuation-row', 'row-check' => $take]) data-valuation-row data-value="{{ $item['amount'] }}" data-floor="{{ $item['floor'] }}">
                    <span class="min-w-0 flex-1">
                        <span class="block truncate">{{ $o?->titleWithYear() ?? $item['ref'] }}</span>
                        <span class="row-sub">
                            @if ($o)<span class="nums">{{ $item['ref'] }}</span>@endif
                            @if ($status === 'check')<span class="text-urgent">VIN другой <span class="nums">{{ $item['vin'] }}</span></span>@endif
                            @if ($status === 'dispute')<span class="text-urgent">номер в тексте дважды</span>@endif
                        </span>
                    </span>
                    <span class="valuation-sum nums">
                        <span data-v>{{ Money::nums($item['amount']) }}</span>@if ($o)<span class="valuation-floor">→ <span data-f>{{ Money::rub($item['floor']) }}</span></span>@endif
                    </span>
                    @if ($take)<span class="check"><input type="checkbox" name="offers[]" value="{{ $o->id }}" @checked($status !== 'check') data-action="valuation#count"></span>@endif
                </label>
            @endforeach
        </div>
    </details>
@endforeach
