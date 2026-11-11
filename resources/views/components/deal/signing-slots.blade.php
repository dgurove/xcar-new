{{-- Когда покупатель сможет подписать — сетка «день × час» (11.11.2026, владелец: «следующие 5 рабочих дней и по часам слоты,
     выбрать сколько угодно — в одни дни может в 12 и 14, в другие только в 9 и 16»). День строкой («12 ноября чт», «Весь день»
     справа — отметить или снять все его часы), под ним часы филиала чипами-галками одним рядом; не влезает — ряд листается вбок
     (до краёв карточки, справа затухает, пока есть куда). Одна форма → `SaveSigningWish`, на кнопке — сколько отмечено
     (`slots_pick_controller`). Отмеченное раньше — отмечено. `back` — куда вернуться. --}}
@props(['deal', 'action', 'back' => null, 'submit' => 'Отправить'])
@php
    use App\Offers\Signing;
    $picked = Signing::wish($deal)?->map(fn ($c) => Signing::slotKey($c))->all() ?? [];
    $grid = Signing::grid($deal);
@endphp
<form method="post" action="{{ $action }}" {{ $attributes->class('slot-days') }} data-controller="slots-pick" data-slots-pick-label-value="{{ $submit }}">
    @csrf
    @if ($back)<input type="hidden" name="back" value="{{ $back }}">@endif
    @foreach ($grid as $date => $slots)
        @php $day = $slots->first(); @endphp
        <div class="slot-day-row" data-slots-pick-target="day">
            <div class="slot-day-head">
                <span class="min-w-0 flex-1"><span class="font-medium">{{ $day->locale('ru')->isoFormat('D MMMM') }}</span> <span class="text-ink-muted">{{ $day->isTomorrow() ? 'завтра' : $day->locale('ru')->isoFormat('dd') }}</span></span>
                <button type="button" class="slot-all" data-action="slots-pick#all">Весь день</button>
            </div>
            <div class="slot-row" data-slots-pick-target="row" data-action="scroll->slots-pick#edge">
                @foreach ($slots as $slot)
                    <label class="time-chip">
                        <input type="checkbox" name="slots[]" value="{{ Signing::slotKey($slot) }}" @checked(in_array(Signing::slotKey($slot), $picked, true)) data-action="slots-pick#update">
                        <span class="time-chip-face nums">{{ $slot->format('G:i') }}</span>
                    </label>
                @endforeach
            </div>
        </div>
    @endforeach
    @error('slots')<p class="field-error">{{ $message }}</p>@enderror
    <x-ui.button type="submit" variant="primary" block data-slots-pick-target="submit">{{ $submit }}</x-ui.button>
</form>
