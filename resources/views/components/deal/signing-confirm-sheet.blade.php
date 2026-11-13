{{-- «Запись подтверждена» (`ConfirmSigning`, 11.11.2026): на какое время записал поставщик — плитками из того, что называл
     менеджер (одно — отмечено само; выбрано одно время — оно), или «Другое время» календарём, если записали иначе. --}}
@props(['id', 'deal', 'exit', 'reply' => null])
@php
    use App\Offers\Signing;
    $slots = Signing::wish($deal) ?? collect();
    // Поставщик написал «записали» (`SigningReply`, 13.11.2026) — это время плиткой и отмечено.
    $booked = $reply?->kind === 'booked' ? $reply->at : null;
    if ($booked && ! $slots->contains(fn ($c) => $c->equalTo($booked))) {
        $slots = $slots->push($booked->copy()->timezone('Europe/Moscow'))->sort()->values();
    }
    if ($deal->signing_at && ! $slots->contains(fn ($c) => $c->equalTo($deal->signing_at))) {
        $slots = $slots->push($deal->signing_at->copy()->timezone('Europe/Moscow'))->sort()->values();
    }
    $pick = $booked ? Signing::slotKey($booked) : ($deal->signing_at ? Signing::slotKey($deal->signing_at) : ($slots->count() === 1 ? Signing::slotKey($slots->first()) : null));
@endphp
<x-ui.sheet :id="$id" title="На когда записали" tall>
    <form method="post" action="/work/deals/{{ $deal->id }}/signing-confirm" class="flex flex-col gap-4">
        @csrf
        <input type="hidden" name="exit" value="{{ $exit->id }}">
        @error('confirm')<x-ui.flash tone="danger">{{ $message }}</x-ui.flash>@enderror
        @if ($slots->isNotEmpty())
            <div class="slot-grid">
                @foreach ($slots as $s)
                    <label class="slot slot-pick"><input type="radio" name="at" value="{{ Signing::slotKey($s) }}" @checked($pick === Signing::slotKey($s))><span class="slot-day">{{ $s->locale('ru')->isoFormat('dd') }} {{ $s->format('d.m') }}</span><span class="slot-time nums">{{ $s->format('G:i') }}</span></label>
                @endforeach
            </div>
        @endif
        <div class="fields">
            <div class="field"><label class="field-label" for="{{ $id }}-other">{{ $slots->isNotEmpty() ? 'Записали на другое время' : 'Когда' }}</label><x-ui.date name="other" :id="$id.'-other'" time default-time="10:00" :min="now()"/></div>
        </div>
        <x-ui.sheet-foot type="submit">Запись подтверждена</x-ui.sheet-foot>
    </form>
</x-ui.sheet>
