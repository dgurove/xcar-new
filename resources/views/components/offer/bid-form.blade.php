{{-- Форма предложения цены: кнопка полной цены и скидки, которые порог не пробивают, поле с разделителями. Нижний порог покупателю не показываем —
     иначе все напишут ровно его; сервер просто не примет цену ниже. --}}
@props(['offer', 'myBid' => null])
@php
    $asking = (int) $offer->asking_price;
    $min = (int) ($offer->minBid() ?? 0);
    // Кнопки скидки — только те, что не уводят ниже порога: сам порог наружу не выдаём.
    $discounts = array_filter([2, 5], fn ($p) => round($asking * (1 - $p / 100) / 1000) * 1000 >= $min);
@endphp
<form method="post" action="/offers/{{ $offer->number }}/stavka" class="mt-6" data-controller="bid draft" data-bid-asking-value="{{ $asking }}">
    @csrf
    @if ($myBid)
        <p class="box-nested mb-4 text-sm">Ваша цена: <span class="nums">{{ \App\Support\Money::rub($myBid->amount) }}</span> — {{ mb_strtolower($myBid->state->label()) }}</p>
    @endif
    @if ($asking)
        <div class="flex flex-wrap gap-2">
            <button type="button" class="btn btn-s btn-quiet nums" data-action="bid#set" data-bid-amount-param="{{ $asking }}">{{ \App\Support\Money::nums($asking) }}</button>
            @foreach ($discounts as $percent)
                <button type="button" class="btn btn-s btn-quiet" data-action="bid#discount" data-bid-percent-param="{{ $percent }}">−{{ $percent }}%</button>
            @endforeach
        </div>
    @endif
    <label for="bid-amount" class="mt-4 block text-sm text-ink-dim">Цена, ₽</label>
    <input type="hidden" name="amount" data-bid-target="amount" value="{{ old('amount', $myBid?->amount) }}">
    <input id="bid-amount" type="text" inputmode="numeric" required class="field-input nums mt-1.5 text-lg" data-bid-target="display" data-action="input->bid#input" value="{{ old('amount', $myBid?->amount ? \App\Support\Money::nums($myBid->amount) : '') }}" autocomplete="off">
    @error('amount')<p class="mt-1.5 text-sm text-danger">{{ $message }}</p>@enderror
    <textarea name="comment" rows="2" class="field-input mt-4 !min-h-0 text-sm" placeholder="Комментарий">{{ old('comment', $myBid?->comment) }}</textarea>
    <button type="submit" class="btn btn-accent mt-4 w-full" data-bid-target="submit">{{ $myBid ? 'Изменить предложение' : 'Подтвердить предложение' }}</button>
</form>
@if ($myBid)
    <form method="post" action="/stavki/{{ $myBid->id }}/otozvat" class="mt-2">@csrf<button type="submit" class="w-full py-2 text-sm text-ink-dim hover:text-danger">Отозвать</button></form>
@endif
