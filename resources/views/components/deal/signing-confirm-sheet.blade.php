{{-- «Запись подтверждена» (`ConfirmSigning`, 11.11.2026): на какое время записал поставщик. Дни и время — что называл менеджер
     (одно — отмечено само), выбрано одно время — оно; поставщик записал на другое — «Другой день» и «Другое время» всеми
     рабочими днями и часами филиала. --}}
@props(['id', 'deal', 'exit'])
@php
    use App\Offers\Signing;
    $wish = Signing::wish($deal);
    $at = $deal->signing_at?->copy()->timezone('Europe/Moscow');
    $days = $wish ? $wish['days'] : collect($at ? [$at->copy()->startOfDay()] : []);
    $times = $wish ? $wish['times'] : ($at ? [$at->format('G:i')] : []);
    $allDays = Signing::workdays($deal, 8)->reject(fn ($d) => $days->contains(fn ($x) => $x->isSameDay($d)))->values();
    $allTimes = array_values(array_diff(Signing::times($deal), $times));
    $dayPick = $at?->toDateString() ?? ($days->count() === 1 ? $days->first()->toDateString() : null);
    $timePick = $at?->format('G:i') ?? (count($times) === 1 ? $times[0] : null);
@endphp
<x-ui.sheet :id="$id" title="На когда записали" tall>
    <form method="post" action="/work/deals/{{ $deal->id }}/signing-confirm" class="flex flex-col gap-4">
        @csrf
        <input type="hidden" name="exit" value="{{ $exit->id }}">
        @error('confirm')<x-ui.flash tone="danger">{{ $message }}</x-ui.flash>@enderror
        <div>
            <p class="field-label mb-2">День</p>
            <div class="slot-grid">
                @foreach ($days as $d)
                    <label class="slot slot-pick"><input type="radio" name="day" value="{{ $d->toDateString() }}" @checked($dayPick === $d->toDateString()) required><span class="slot-day">{{ $d->locale('ru')->isoFormat('dd') }}</span><span class="slot-time nums">{{ $d->format('d.m') }}</span></label>
                @endforeach
            </div>
            @if ($allDays->isNotEmpty())
                <details class="mt-2">
                    <summary class="cursor-pointer text-sm text-ink-muted">Другой день</summary>
                    <div class="slot-grid mt-2">
                        @foreach ($allDays as $d)
                            <label class="slot slot-pick"><input type="radio" name="day" value="{{ $d->toDateString() }}" required><span class="slot-day">{{ $d->locale('ru')->isoFormat('dd') }}</span><span class="slot-time nums">{{ $d->format('d.m') }}</span></label>
                        @endforeach
                    </div>
                </details>
            @endif
        </div>
        <div>
            <p class="field-label mb-2">Время</p>
            <div class="slot-grid">
                @foreach ($times as $t)
                    <label class="slot slot-pick slot-pick-time"><input type="radio" name="time" value="{{ $t }}" @checked($timePick === $t) required><span class="slot-time nums">{{ $t }}</span></label>
                @endforeach
            </div>
            @if ($allTimes)
                <details class="mt-2">
                    <summary class="cursor-pointer text-sm text-ink-muted">Другое время</summary>
                    <div class="slot-grid mt-2">
                        @foreach ($allTimes as $t)
                            <label class="slot slot-pick slot-pick-time"><input type="radio" name="time" value="{{ $t }}" required><span class="slot-time nums">{{ $t }}</span></label>
                        @endforeach
                    </div>
                </details>
            @endif
        </div>
        <x-ui.sheet-foot type="submit">Запись подтверждена</x-ui.sheet-foot>
    </form>
</x-ui.sheet>
