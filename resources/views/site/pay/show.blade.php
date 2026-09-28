{{-- Оплата по ссылке: сумма крупно, за что и кому списком, «Оплатить» — одно действие. Оплачено или отменено — полоса
     состояния вместо кнопки. Строк счёта и вознаграждения нет: их видит только менеджер. --}}
@php
    use App\Support\Money; use App\Billing\Acquiring\PayLinkState; use App\Billing\Acquiring\PayMethod;
    $attempt = $link->state === PayLinkState::Paid ? $link->attempts->firstWhere('payment_id', $link->payment_id) : null;
@endphp
<x-pickup.layout title="Оплата">
    <div class="text-sm text-ink-muted">Счёт {{ $invoice->label() }} от {{ $invoice->issued_at->translatedFormat('j F Y') }}</div>
    <div class="nums mt-1 text-[32px] font-semibold leading-tight">{{ Money::exact($link->state === PayLinkState::Paid ? ($link->payment?->amount ?? $link->amount) : $link->amount) }}</div>
    @if ($invoice->offer)<div class="mt-1 text-ink-muted">{{ $invoice->offer->titleWithYear() }}</div>@endif

    @if ($link->state === PayLinkState::Paid)
        <div class="pass-card mt-4"><div class="pass-state pass-state--open"><x-ui.icon name="check-circle" class="size-5"/>Оплачено {{ $link->paid_at->translatedFormat('j F, H:i') }}@if ($attempt) {{ PayMethod::label($attempt->method) }}@endif</div></div>
    @elseif ($link->state === PayLinkState::Canceled)
        <div class="pass-card mt-4"><div class="pass-state pass-state--closed"><x-ui.icon name="x" class="size-5"/>Ссылка больше не действует</div></div>
    @elseif ($processing)
        <div class="pass-card mt-4"><div class="pass-state pass-state--urgent"><x-ui.icon name="refresh" class="size-5"/>Банк ещё проверяет оплату</div></div>
        <x-ui.button variant="secondary" :href="'/pay/'.$link->code.'?back=1'" block class="mt-4">Обновить</x-ui.button>
    @else
        <form method="post" action="/pay/{{ $link->code }}" class="mt-4" data-turbo="false">
            @csrf
            <x-ui.button block>Оплатить</x-ui.button>
        </form>
        @error('link')<div class="mt-2 text-sm text-danger">{{ $message }}</div>@enderror
        @if ($failed)<div class="mt-2 text-sm text-danger">Оплата сейчас недоступна, попробуйте через несколько минут</div>@endif
    @endif

    <div class="list mt-4">
        <div class="row justify-between"><span class="text-ink-muted">Получатель</span><span class="text-right">{{ $self->name }}</span></div>
        @if ($link->payer_name)<div class="row justify-between"><span class="text-ink-muted">Плательщик</span><span class="text-right">{{ $link->payer_name }}</span></div>@endif
    </div>
</x-pickup.layout>
