{{-- Форма предложения цены: быстрые кнопки, поле с разделителями, минимум. --}}
@props(['offer', 'myBid' => null])
@php $asking = (int) $offer->asking_price; $min = (int) ($offer->minBid() ?? 0); @endphp
<form method="post" action="/offers/{{ $offer->number }}/stavka" class="mt-6" data-controller="bid" data-bid-asking-value="{{ $asking }}" data-bid-min-value="{{ $min }}">
    @csrf
    @if ($myBid)
        <p class="box-nested mb-4 text-sm">Ваша цена: <span class="nums">{{ number_format($myBid->amount, 0, '', ' ') }} ₽</span> — {{ mb_strtolower($myBid->state->label()) }}</p>
    @endif
    @if ($asking)
        <div class="flex flex-wrap gap-2">
            <button type="button" class="btn btn-s btn-quiet nums" data-action="bid#set" data-bid-amount-param="{{ $asking }}">{{ number_format($asking, 0, '', ' ') }}</button>
            @foreach ([2, 5] as $percent)
                <button type="button" class="btn btn-s btn-quiet" data-action="bid#discount" data-bid-percent-param="{{ $percent }}">−{{ $percent }}%</button>
            @endforeach
        </div>
    @endif
    <label for="bid-amount" class="mt-4 block text-sm text-ink-dim">Цена, ₽</label>
    <input type="hidden" name="amount" data-bid-target="amount" value="{{ old('amount', $myBid?->amount) }}">
    <input id="bid-amount" type="text" inputmode="numeric" required class="field-input nums mt-1.5 text-lg" data-bid-target="display" data-action="input->bid#input" value="{{ old('amount', $myBid?->amount ? number_format($myBid->amount, 0, '', ' ') : '') }}" autocomplete="off">
    @if ($min)<p class="mt-1.5 text-sm text-danger" data-bid-target="low" hidden>Минимум — <span class="nums">{{ number_format($min, 0, '', ' ') }}</span> ₽</p>@endif
    @error('amount')<p class="mt-1.5 text-sm text-danger">{{ $message }}</p>@enderror
    <textarea name="comment" rows="2" class="field-input mt-4 !min-h-0 text-sm" placeholder="Комментарий">{{ old('comment', $myBid?->comment) }}</textarea>
    <button type="submit" class="btn btn-accent mt-4 w-full" data-bid-target="submit">{{ $myBid ? 'Изменить предложение' : 'Подтвердить предложение' }}</button>
</form>
@if ($myBid)
    <form method="post" action="/stavki/{{ $myBid->id }}/otozvat" class="mt-2">@csrf<button type="submit" class="w-full py-2 text-sm text-ink-dim hover:text-danger">Отозвать</button></form>
@endif
