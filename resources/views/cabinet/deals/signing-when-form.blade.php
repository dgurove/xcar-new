{{-- Когда покупатель сможет подписать — в два шага (`x-deal.signing-when`, сайт, CRM и чат; 11.11.2026, владелец: «сначала дни —
     можно несколько, потом время — можно несколько, с возможностью вернуться назад»): ① рабочие дни филиала с завтра плитками
     с галкой, «Дальше» — когда отмечен хоть один; ② сверху «‹ чт 12.11, пт 13.11» (назад к дням), время по часу в часах
     филиала плитками с галкой и «Весь день», «Отправить». Всё — одна форма (`signing_when_controller` только переключает шаги)
     → `SaveSigningWish`. Названо раньше — отмечено. `back` — куда вернуться (чат). --}}
@php
    $wish = \App\Offers\Signing::wish($deal);
    $pickedDays = $wish ? $wish['days']->map->toDateString()->all() : [];
    $pickedTimes = $wish['times'] ?? [];
    $days = \App\Offers\Signing::workdays($deal);
    $times = \App\Offers\Signing::times($deal);
    $dayLabel = fn ($d) => ($d->isTomorrow() ? 'завтра' : $d->locale('ru')->isoFormat('dd'));
@endphp
<form method="post" action="{{ $action }}" class="signing-when" data-controller="signing-when">
    @csrf
    @if ($back ?? null)<input type="hidden" name="back" value="{{ $back }}">@endif
    <div class="sw-step" data-signing-when-target="days">
        <p class="sw-hint">Дни, можно несколько</p>
        <div class="slot-grid">
            @foreach ($days as $day)
                <label class="slot slot-pick">
                    <input type="checkbox" name="days[]" value="{{ $day->toDateString() }}" data-label="{{ $day->locale('ru')->isoFormat('dd') }} {{ $day->format('d.m') }}" @checked(in_array($day->toDateString(), $pickedDays, true)) data-action="signing-when#update">
                    <span class="slot-day">{{ $dayLabel($day) }}</span>
                    <span class="slot-time nums">{{ $day->format('d.m') }}</span>
                    <x-ui.icon name="check" class="slot-check size-3.5"/>
                </label>
            @endforeach
        </div>
        @error('days')<p class="field-error">{{ $message }}</p>@enderror
        <x-ui.button type="button" variant="primary" block data-signing-when-target="next" data-action="signing-when#next">Дальше</x-ui.button>
    </div>
    <div class="sw-step" data-signing-when-target="times" hidden>
        <button type="button" class="sw-back" data-action="signing-when#back">
            <x-ui.icon name="chevron-left" class="size-5 shrink-0"/>
            <span class="min-w-0 flex-1 truncate" data-signing-when-target="summary"></span>
            <span class="text-sm text-ink-muted">Дни</span>
        </button>
        <div class="flex items-center gap-3">
            <p class="sw-hint min-w-0 flex-1">Время, можно несколько</p>
            <label class="choice choice-s shrink-0"><input type="checkbox" switch data-signing-when-target="all" data-action="signing-when#all"><span>Весь день</span></label>
        </div>
        <div class="slot-grid">
            @foreach ($times as $time)
                <label class="slot slot-pick slot-pick-time">
                    <input type="checkbox" name="times[]" value="{{ $time }}" @checked(in_array($time, $pickedTimes, true)) data-action="signing-when#update">
                    <span class="slot-time nums">{{ $time }}</span>
                    <x-ui.icon name="check" class="slot-check size-3.5"/>
                </label>
            @endforeach
        </div>
        @error('times')<p class="field-error">{{ $message }}</p>@enderror
        @error('at')<p class="field-error">{{ $message }}</p>@enderror
        <x-ui.button type="submit" variant="primary" block data-signing-when-target="submit">{{ $submit ?? 'Отправить' }}</x-ui.button>
    </div>
</form>
