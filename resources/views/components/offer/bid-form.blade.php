{{-- Форма предложения цены: кнопка полной цены и скидки (доля разницы между заявленной и продажной, `Offer::bidDiscounts`),
     которые порог не пробивают, поле с разделителями. Нижний порог не показываем — иначе все напишут ровно его; сервер
     просто не примет цену ниже. compact — в действиях карточки рядом со списком: поле с кнопкой в строку, скидки пилюлями.
     Без offer — цена машины закупки (только compact): своя дверь action и отзыв withdraw, без скидок, гаража и «за менеджера». --}}
@props(['offer' => null, 'myBid' => null, 'compact' => false, 'action' => null, 'withdraw' => null])
@php
    $asking = $offer ? (int) $offer->asking_price : 0;
    $discounts = $offer ? $offer->bidDiscounts(auth()->user()) : [];
    $action ??= '/offers/'.$offer?->number.'/confirm';
    $withdraw ??= $myBid ? '/confirmations/'.$myBid->id.'/withdraw' : null;
@endphp
@php
    // Админ подтверждает за менеджера (08.10.2026): та же форма, в шторке в конце — за кого. Можно ли в гараж, решит
    // менеджер (`PlaceBidFor`), поэтому админу «В гараж» — по галке предложения.
    $staff = $offer && auth()->user()->isAdmin() && ! auth()->user()->isManager();
    // Можно в гараж — «Подтвердить» спрашивает, куда берёт; без галки — отправляет цену, как раньше.
    $garage = $offer && ($staff ? (bool) $offer->garage_allowed : $offer->garageAllowedFor(auth()->user()));
@endphp
@if ($compact)
    @php $label = $offer ? 'Цена, ₽' : 'Предложение, ₽'; @endphp
    <form method="post" action="{{ $action }}" class="flex w-full flex-col gap-2" @if ($offer) data-telegram-moment="bid" @endif data-controller="bid" data-bid-asking-value="{{ $asking }}" @if ($offer) data-bid-garage-value="{{ $garage ? 'true' : 'false' }}" data-bid-staff-value="{{ $staff ? 'true' : 'false' }}" data-action="submit->bid#guard change->bid#update" @else data-bid-min-value="0" @endif>
        @csrf
        <div class="flex gap-2">
            <input type="hidden" name="amount" data-bid-target="amount" value="{{ old('amount', $myBid?->amount) }}">
            <input type="text" @unless ($garage) required @endunless inputmode="decimal" autocomplete="off" enterkeyhint="go" data-check-text="Укажите цену" class="field-input field-s nums min-w-0 flex-1" placeholder="{{ $label }}" aria-label="{{ $label }}" data-bid-target="display" data-action="input->bid#input" value="{{ old('amount', $myBid?->amount ? \App\Support\Money::nums($myBid->amount) : '') }}" data-detail-focus>
            <button type="submit" class="btn btn-s btn-accent shrink-0" data-bid-target="submit">{{ $myBid ? 'Изменить' : ($offer ? 'Подтвердить' : 'Предложить') }}</button>
        </div>
        @error('amount')<p class="text-sm text-danger">{{ $message }}</p>@enderror
        @if ($offer)
            @error('manager_id')<p class="text-sm text-danger">{{ $message }}</p>@enderror
            <div class="flex flex-wrap items-center gap-1.5">
                @if ($asking)
                    <button type="button" class="pill pill-plain nums" data-action="bid#set" data-bid-amount-param="{{ $asking }}">{{ \App\Support\Money::nums($asking) }}</button>
                    @foreach ($discounts as $percent => $price)<button type="button" class="pill pill-plain nums" data-action="bid#set" data-bid-amount-param="{{ $price }}">−{{ $percent }}%</button>@endforeach
                @endif
                <input name="comment" class="field-input field-s min-w-0 flex-1 sm:text-sm" placeholder="Комментарий" value="{{ old('comment', $myBid?->comment) }}">
            </div>
            @if ($garage || $staff)<x-offer.bid-choice :offer="$offer" :garage="$garage" :staff="$staff"/>@endif
        @else
            <input name="comment" class="field-input field-s sm:text-sm" placeholder="Комментарий" value="{{ old('comment', $myBid?->comment) }}">
        @endif
    </form>
    @if ($myBid && $offer)
        <span class="text-sm text-ink-muted">@if ($myBid->isGarage())<span class="font-semibold text-ink">В гараж</span>@else Ваша цена <span class="nums font-semibold text-ink">{{ \App\Support\Money::rub($myBid->amount) }}</span>@endif, {{ mb_strtolower($myBid->state->label()) }}</span>
    @endif
    @if ($myBid)<form method="post" action="{{ $withdraw }}" class="contents">@csrf<button type="submit" class="pill pill-plain">{{ $offer ? 'Отозвать' : 'Отозвать цену' }}</button></form>@endif
@else
<form method="post" action="{{ $action }}" class="mt-6" data-telegram-moment="bid" data-controller="bid draft" data-bid-asking-value="{{ $asking }}" data-bid-garage-value="{{ $garage ? 'true' : 'false' }}" data-bid-staff-value="{{ $staff ? 'true' : 'false' }}" data-action="submit->bid#guard change->bid#update">
    @csrf
    @if ($myBid?->isGarage())
        <p class="mb-4 text-sm text-ink-muted"><span class="font-semibold text-ink">В гараж</span>, {{ mb_strtolower($myBid->state->label()) }}</p>
    @elseif ($myBid)
        <p class="mb-4 text-sm text-ink-muted">Ваша цена <span class="nums font-semibold text-ink">{{ \App\Support\Money::rub($myBid->amount) }}</span>, {{ mb_strtolower($myBid->state->label()) }}</p>
    @endif
    @if ($asking)
        <div class="flex flex-wrap gap-2">
            <button type="button" class="btn btn-s btn-quiet nums" data-action="bid#set" data-bid-amount-param="{{ $asking }}">{{ \App\Support\Money::nums($asking) }}</button>
            @foreach ($discounts as $percent => $price)
                <button type="button" class="btn btn-s btn-quiet" data-action="bid#set" data-bid-amount-param="{{ $price }}">−{{ $percent }}%</button>
            @endforeach
        </div>
    @endif
    <label for="bid-amount" class="mt-4 block text-sm text-ink-dim">Цена, ₽</label>
    <input type="hidden" name="amount" data-bid-target="amount" value="{{ old('amount', $myBid?->amount) }}">
    <input id="bid-amount" type="text" @unless ($garage) required @endunless inputmode="decimal" enterkeyhint="go" data-check-text="Укажите цену" class="field-input nums mt-1.5 text-lg" data-bid-target="display" data-action="input->bid#input" value="{{ old('amount', $myBid?->amount ? \App\Support\Money::nums($myBid->amount) : '') }}" autocomplete="off">
    @error('amount')<p class="mt-1.5 text-sm text-danger">{{ $message }}</p>@enderror
    <textarea name="comment" rows="2" class="field-input mt-4 !min-h-0 sm:text-sm" placeholder="Комментарий">{{ old('comment', $myBid?->comment) }}</textarea>
    <button type="submit" class="btn btn-accent mt-4 w-full" data-bid-target="submit">{{ $myBid ? 'Изменить предложение' : 'Подтвердить предложение' }}</button>
    @error('manager_id')<p class="mt-1.5 text-sm text-danger">{{ $message }}</p>@enderror
    @if ($garage || $staff)<x-offer.bid-choice :offer="$offer" :garage="$garage" :staff="$staff"/>@endif
</form>
@if ($myBid)
    <form method="post" action="{{ $withdraw }}" class="mt-2">@csrf<button type="submit" class="w-full py-2 text-sm text-ink-dim hover:text-danger">Отозвать</button></form>
@endif
@endif
