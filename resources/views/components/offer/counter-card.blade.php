{{-- Контрпредложение менеджеру (26.10.2026): цена админа крупно, своя — строкой ниже, комментарий цитатой. «Согласен» —
     сразу сделка (переспрашивает: машина закрепится по этой цене), «Отказаться» — своя цена остаётся. Стоит над формой
     подтверждения и над «Ваше подтверждение», пока контр живой (`Bid::hasPendingCounter`). --}}
@props(['bid'])
@php use App\Support\Money; @endphp
@if ($bid?->hasPendingCounter())
    <div {{ $attributes->class('counter-card') }}>
        <div class="text-sm text-accent-text">Контрпредложение</div>
        <div class="nums mt-1 text-[32px] font-bold leading-tight">{{ Money::rub($bid->counter_amount) }}</div>
        <div class="nums mt-1 text-sm text-ink-muted">ваша цена {{ Money::rub($bid->amount) }}, на {{ Money::rub($bid->counter_amount - $bid->amount) }} выше</div>
        @if ($bid->counter_comment)<p class="mt-2 text-sm">«{{ $bid->counter_comment }}»</p>@endif
        <div class="mt-4 flex flex-col gap-2">
            <form method="post" action="/confirmations/{{ $bid->id }}/counter/answer" data-turbo-confirm="Согласиться на {{ Money::rub($bid->counter_amount) }}?" data-turbo-confirm-text="ТС закрепится за вами по этой цене" data-turbo-confirm-label="Согласен">
                @csrf<input type="hidden" name="agree" value="1">
                <button type="submit" class="btn btn-accent w-full nums">Согласен за {{ Money::rub($bid->counter_amount) }}</button>
            </form>
            <form method="post" action="/confirmations/{{ $bid->id }}/counter/answer" data-turbo-confirm="Отказаться от контрпредложения?" data-turbo-confirm-text="Ваша цена {{ Money::rub($bid->amount) }} останется в силе" data-turbo-confirm-label="Отказаться">
                @csrf<input type="hidden" name="agree" value="0">
                <button type="submit" class="btn btn-quiet w-full">Отказаться</button>
            </form>
        </div>
        @error('counter')<p class="mt-2 text-sm text-danger">{{ $message }}</p>@enderror
    </div>
@endif
