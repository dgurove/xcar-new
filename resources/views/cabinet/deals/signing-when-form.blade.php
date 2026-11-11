{{-- День и время подписания (`x-deal.signing-when`, сайт, CRM и чат): рабочие дни филиала с завтра и время по часу в его часах —
     плитками-радио, «Отправить» → `SaveSigningWish` (на шаге выбора — сразу ответ шага). `back` — куда вернуться (чат). --}}
@php
    $at = \App\Offers\Signing::wishAt($deal);
    $pickedDay = $at?->toDateString();
    $pickedTime = $at ? $at->format('G:i') : null;
    $days = \App\Offers\Signing::workdays($deal);
    $times = \App\Offers\Signing::times($deal);
@endphp
<form method="post" action="{{ $action }}" class="signing-when flex flex-col gap-3">
    @csrf
    @if ($back ?? null)<input type="hidden" name="back" value="{{ $back }}">@endif
    <div class="slot-grid">
        @foreach ($days as $day)
            <label class="slot slot-pick">
                <input type="radio" name="day" value="{{ $day->toDateString() }}" @checked($pickedDay === $day->toDateString()) required>
                <span class="slot-day">{{ $day->isTomorrow() ? 'завтра' : $day->locale('ru')->isoFormat('dd') }}</span>
                <span class="slot-time nums">{{ $day->format('d.m') }}</span>
            </label>
        @endforeach
    </div>
    <div class="slot-grid">
        @foreach ($times as $time)
            <label class="slot slot-pick slot-pick-time">
                <input type="radio" name="time" value="{{ $time }}" @checked($pickedTime === $time) required>
                <span class="slot-time nums">{{ $time }}</span>
            </label>
        @endforeach
    </div>
    @error('at')<p class="field-error">{{ $message }}</p>@enderror
    @error('day')<p class="field-error">{{ $message }}</p>@enderror
    @error('time')<p class="field-error">{{ $message }}</p>@enderror
    @if ($inSheet ?? false)
        <x-ui.sheet-foot type="submit">{{ $submit ?? 'Отправить' }}</x-ui.sheet-foot>
    @else
        <x-ui.button type="submit" variant="primary" block>{{ $submit ?? 'Отправить' }}</x-ui.button>
    @endif
</form>
