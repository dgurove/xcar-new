{{-- Форма дней покупателя (`x-deal.signing-days`, сайт и CRM): плитки дней с галкой, часть дня, «Сохранить». --}}
<form method="post" action="{{ $action }}" class="flex flex-col gap-3">
    @csrf
    <div class="slot-grid sm:grid-cols-6">
        @foreach ($days as $day)
            <label class="slot slot-pick">
                <input type="checkbox" name="days[]" value="{{ $day->toDateString() }}" @checked($picked->contains($day->toDateString()))>
                <span class="slot-day">{{ $day->isTomorrow() ? 'завтра' : $day->locale('ru')->isoFormat('dd') }}</span>
                <span class="slot-time nums">{{ $day->format('d.m') }}</span>
            </label>
        @endforeach
    </div>
    <x-ui.segment name="part" :options="\App\Offers\Signing::PARTS" :value="$deal->signing_wish['part'] ?? 'any'" class="[&_span]:whitespace-nowrap"/>
    @if ($office['by_appointment'] || $office['address'])
        <p class="text-sm text-ink-muted">Филиал {{ preg_replace('/^0(\d)/', '$1', $office['from']) }}–{{ $office['to'] }}{{ $office['by_appointment'] ? ', только по записи' : '' }}</p>
    @endif
    @error('days')<p class="field-error">{{ $message }}</p>@enderror
    @if ($inSheet)
        <x-ui.sheet-foot type="submit">Сохранить</x-ui.sheet-foot>
    @else
        <x-ui.button type="submit" variant="primary" block>Сохранить</x-ui.button>
    @endif
</form>
