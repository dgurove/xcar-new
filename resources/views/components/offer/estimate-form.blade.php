{{-- Оценка цены продажи менеджером (11.10.2026) — на месте цены и подтверждения, на телефоне листом по «Оценить» снизу
     (`DealPlacement`). Как ввод суммы в Apple Pay: сумма крупно по центру, под ней заявленная (закупочной на сайте нет), ниже
     полоса 48 часов — сколько осталось (за 6 ч оранжевая, вышло — красная) — и «Отправить оценку». Оценил — галочка
     рисуется, сумма, когда отправлена, «Изменить оценку»; отклонили — словом сверху, поле снова пустое, срок прежний. --}}
@props(['offer', 'estimate'])
@php
    $declared = $offer->declaredPrice();
    $given = $estimate->isGiven();
    $due = $estimate->due_at;
    $total = \App\Offers\Estimate::HOURS * 3600;
    $left = max(0, now()->diffInSeconds($due, false));
    $tone = match (true) { $due->isPast() => 'danger', $left <= \App\Offers\Estimate::REMIND_HOURS * 3600 => 'urgent', default => 'accent' };
    $editing = $given && $errors->has('amount');
@endphp
<div class="estimate">
    @if ($given)
        <div class="money-hero">
            <svg class="estimate-ok" viewBox="0 0 64 64" aria-hidden="true"><circle cx="32" cy="32" r="28"/><path d="M20 33l8 8 16-17"/></svg>
            <span class="money-hero-cap">Ваша оценка</span>
            <span class="money-hero-sum nums">{{ \App\Support\Money::nums($estimate->amount) }} <small>₽</small></span>
            <span class="money-hero-state text-ink-muted">отправлена {{ $estimate->estimated_at?->translatedFormat($estimate->estimated_at->isToday() ? 'H:i' : 'j M, H:i') }}</span>
            @if ($declared)<span class="money-hero-car nums">заявленная {{ \App\Support\Money::rub($declared) }}</span>@endif
        </div>
    @endif

    @if ($given)<details class="group mt-5" @if ($editing) open @endif><summary class="btn btn-quiet w-full list-none group-open:hidden [&::-webkit-details-marker]:hidden">Изменить оценку</summary>@endif
    <form method="post" action="/offers/{{ $offer->number }}/estimate" class="flex flex-col">
        @csrf
        @if ($estimate->rejected_at && ! $given)<x-ui.flash tone="urgent" class="mb-3 text-center">Оценку {{ \App\Support\Money::rub($estimate->rejected_amount) }} отклонили</x-ui.flash>@endif
        <div class="money-hero">
            <label for="estimate-amount" class="money-hero-cap">{{ $given ? 'Новая оценка' : 'Ваша оценка' }}</label>
            <span class="mt-1 flex items-baseline justify-center gap-2">
                <input id="estimate-amount" type="text" name="amount" required inputmode="decimal" enterkeyhint="send" autocomplete="off" placeholder="0" data-check-text="Укажите цену"
                    class="pay-amount estimate-amount nums" data-controller="digits" data-action="input->digits#format" value="{{ old('amount') }}" @unless ($given) autofocus @endunless>
                <span class="text-2xl font-medium text-ink-muted">₽</span>
            </span>
            @error('amount')<span class="mt-2 text-sm text-danger">{{ $message }}</span>@enderror
            @if ($declared && ! $given)<span class="money-hero-car mt-2 nums">заявленная {{ \App\Support\Money::rub($declared) }}</span>@endif
        </div>
        @unless ($given)
            <div class="estimate-due estimate-due--{{ $tone }}">
                <span class="estimate-bar" role="progressbar" aria-valuemin="0" aria-valuemax="{{ $total }}" aria-valuenow="{{ $left }}"><span style="width: {{ $due->isPast() ? 100 : max(3, round($left / $total * 100)) }}%"></span></span>
                <x-offer.estimate-left :estimate="$estimate" class="!text-current"/>
            </div>
        @endunless
        <button type="submit" class="btn btn-accent mt-5 w-full">{{ $given ? 'Сохранить' : 'Отправить оценку' }}</button>
    </form>
    @if ($given)</details>@endif
</div>
