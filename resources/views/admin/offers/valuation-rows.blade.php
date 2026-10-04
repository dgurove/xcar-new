{{-- Итог «Оценить → Из текста» (OfferValuationController::preview): группы строк, у тех, что можно взять, — галка.
     data-value / data-floor — числа, которые valuation_controller прогоняет счётчиком. --}}
@php
    use App\Support\Money;
    $groups = collect($items)->groupBy('status');
    $titles = ['fill' => 'Заполним', 'change' => 'Изменим', 'check' => 'Проверьте', 'dispute' => 'Разные суммы', 'same' => 'Уже так', 'missing' => 'Нет в CRM'];
@endphp
@foreach (\App\Offers\Actions\MatchValuations::ORDER as $status)
    @continue(! $groups->has($status))
    @php $rows = $groups[$status]; $folded = in_array($status, ['same', 'missing'], true); @endphp
    <details class="valuation-group" data-status="{{ $status }}" @unless ($folded) open @endunless>
        <summary class="list-head">{{ $titles[$status] }} <span class="nums text-ink-dim">{{ $rows->count() }}</span></summary>
        <div class="list mb-3">
            @foreach ($rows as $item)
                @php $o = $item['offer']; $take = $o && in_array($status, ['fill', 'change', 'check'], true); @endphp
                <label @class(['row valuation-row', 'row-check' => $take]) data-valuation-row data-value="{{ $item['amount'] }}" data-floor="{{ $item['floor'] }}">
                    <span class="min-w-0 flex-1">
                        <span class="block truncate">{{ $o?->titleWithYear() ?? $item['ref'] }}</span>
                        <span class="row-sub">
                            @if ($o)<span class="nums">{{ $item['ref'] }}</span>@endif
                            @if ($status === 'change')<span>было <span class="nums">{{ Money::nums($o->value) }}</span></span>@endif
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
