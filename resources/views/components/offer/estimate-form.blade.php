{{-- Оценка цены продажи менеджером на странице машины (11.10.2026) — на месте цены и подтверждения: заявленная ориентиром
     (закупочной на сайте нет), срок как у приёма, поле и «Отправить». Оценил — его сумма и «Изменить», пока админ не
     решил; отклонили — снова поле, срок прежний. --}}
@props(['offer', 'estimate'])
@php
    $declared = $offer->declaredPrice();
    $given = $estimate->isGiven();
@endphp
@if ($given)
    <div class="nums text-[32px] font-bold leading-tight" data-controller="fit">{{ \App\Support\Money::nums($estimate->amount) }}&nbsp;₽</div>
    <p class="mt-1 text-sm text-ink-muted">ваша оценка</p>
@endif
@if ($declared)<p @class(['nums text-sm text-ink-dim', 'mt-2' => $given])>заявленная {{ \App\Support\Money::nums($declared) }}</p>@endif
@unless ($given)
    <p class="mt-3 flex items-baseline justify-between gap-3 text-sm text-ink-muted">
        <span>@if ($estimate->rejected_at)<span class="text-urgent">Оценку отклонили,</span> оцените @else Оценить @endif до {{ $estimate->due_at->translatedFormat('j M, H:i') }}</span>
        <x-offer.estimate-left :estimate="$estimate" class="text-base font-semibold"/>
    </p>
@endunless
{{-- Оценил — поле спрятано за «Изменить»: сумма уже крупно сверху, второй раз её не пишем. --}}
@if ($given)<details class="group mt-6" @if ($errors->has('amount')) open @endif><summary class="btn btn-quiet w-full list-none group-open:hidden [&::-webkit-details-marker]:hidden">Изменить</summary>@endif
<form method="post" action="/offers/{{ $offer->number }}/estimate" @class(['mt-6' => ! $given])>
    @csrf
    <label for="estimate-amount" class="block text-sm text-ink-dim">{{ $given ? 'Новая оценка, ₽' : 'Ваша оценка, ₽' }}</label>
    <input id="estimate-amount" type="text" name="amount" required inputmode="decimal" enterkeyhint="go" autocomplete="off" data-check-text="Укажите цену"
        class="field-input nums mt-1.5 text-lg" data-controller="digits" data-action="input->digits#format" value="{{ old('amount') }}">
    @error('amount')<p class="mt-1.5 text-sm text-danger">{{ $message }}</p>@enderror
    <button type="submit" class="btn btn-accent mt-4 w-full">{{ $given ? 'Сохранить' : 'Отправить' }}</button>
</form>
@if ($given)</details>@endif
