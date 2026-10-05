{{-- Оплата по ссылке, как экран оплаты в приложении: карточка — фото ТС кружком, что это, счёт и сумма крупно; ниже кому,
     кто платит и куда придёт чек; «Оплатить N» и способы значками. Оплачено, проверяется, ссылка не действует — экран
     состояния с большим кружком вместо кнопки. Строк счёта и вознаграждения нет: их видит только менеджер. Внизу мелко —
     чья это оплата и документы: страница открыта без входа. Кто платит и почту для чека спрашиваем здесь, у самого
     плательщика: ссылка заводится вместе со счётом, и почты может не быть. Известные — уже в полях. Вернулся с ЮKassa
     с отказом — над кнопкой «Оплата не прошла» и почему, кнопка та же. --}}
@php
    use App\Support\Money; use App\Billing\Acquiring\PayLinkState; use App\Billing\Acquiring\PayMethod;
    $attempt = $link->state === PayLinkState::Paid ? $link->attempts->firstWhere('payment_id', $link->payment_id) : null;
    $offer = $invoice->offer;
    // Почта для чека — не целиком: ссылку могут переслать.
    $mail = $link->payer_email ? preg_replace_callback('/^(.)(.*)(@.*)$/u', fn ($m) => $m[1].str_repeat('•', min(6, max(1, mb_strlen($m[2])))).$m[3], $link->payer_email) : null;
    $amount = $link->state === PayLinkState::Paid ? ($link->payment?->amount ?? $link->amount) : $link->amount;
    [$icon, $tone, $title] = match (true) {
        $link->state === PayLinkState::Paid => ['check', 'open', 'Оплачено'],
        $link->state === PayLinkState::Canceled => ['x', 'muted', 'Ссылка больше не действует'],
        (bool) $processing => ['clock', 'urgent', 'Банк проверяет оплату'],
        default => [null, null, null],
    };
@endphp
<x-pickup.layout title="Оплата">
    @if ($icon)
        <div class="money-hero py-6">
            <span class="row-icon row-icon-{{ $tone ?: 'plain' }} mb-3 !size-20"><x-ui.icon :name="$icon" class="size-10"/></span>
            <h1 class="text-2xl">{{ $title }}</h1>
            @if ($link->state !== PayLinkState::Canceled)<span class="nums text-[32px] font-semibold leading-tight">{{ Money::exact($amount) }}</span>@endif
            @if ($link->state === PayLinkState::Paid)
                <span class="text-ink-muted">{{ $link->paid_at->translatedFormat('j F, H:i') }}@if ($attempt) {{ PayMethod::label($attempt->method) }}@endif</span>
            @endif
        </div>
        @if ($processing)
            <x-ui.button :href="'/pay/'.$link->code.'?back=1'" block>Обновить</x-ui.button>
        @endif
    @else
        <div class="box money-hero !p-6">
            @if ($offer)<span class="money-hero-photo"><x-offer.photo :media="$offer->mainPhoto()" sizes="64px"/></span><span class="font-medium">{{ $offer->titleWithYear() }}</span>@endif
            <span class="text-sm text-ink-muted">Счёт {{ $invoice->label() }} от {{ $invoice->issued_at->translatedFormat('j F Y') }}</span>
            <span class="nums mt-2 text-[32px] font-semibold leading-tight">{{ Money::exact($amount) }}</span>
        </div>
        @if ($declined)
            <div class="mt-4 flex items-center gap-3 rounded-(--radius-l) bg-danger/10 p-4">
                <x-ui.icon name="x" class="size-5 shrink-0 text-danger"/>
                <span class="min-w-0 flex-1"><span class="block font-medium">Оплата не прошла</span><span class="text-sm text-ink-muted">{{ Str::ucfirst($declined->cancelLabel() ?? 'отклонено') }}, деньги не списаны</span></span>
            </div>
        @endif
        <form method="post" action="/pay/{{ $link->code }}" class="mt-4 flex flex-col gap-3" data-turbo="false">
            @csrf
            <x-ui.field name="name" id="pay-name" label="Кто платит" :value="old('name', $link->payer_name)" autocomplete="name"/>
            <x-ui.field name="email" id="pay-email" label="Почта для чека" type="email" :value="old('email', $link->payer_email)" required/>
            <x-ui.button block>{{ $declined ? 'Оплатить ещё раз' : 'Оплатить '.Money::exact($amount) }}</x-ui.button>
        </form>
        @error('link')<div class="mt-2 text-center text-sm text-danger">{{ $message }}</div>@enderror
        @if ($failed)<div class="mt-2 text-center text-sm text-danger">Оплата сейчас недоступна, попробуйте через несколько минут</div>@endif
        <div class="pay-methods mt-3">
            <span><x-ui.icon name="sbp" class="size-4"/>СБП</span>
            <span><x-ui.icon name="sberpay" class="size-4"/>SberPay</span>
            <span><x-ui.icon name="card" class="size-4"/>Карта</span>
        </div>
    @endif

    <div class="list mt-6">
        <div class="row"><span class="min-w-0 flex-1 text-ink-muted">Получатель</span><span class="text-right">{{ $self->name }}</span></div>
        @if ($link->payer_name && $icon)<div class="row"><span class="min-w-0 flex-1 text-ink-muted">Плательщик</span><span class="text-right">{{ $link->payer_name }}</span></div>@endif
        @if ($mail && $link->state === PayLinkState::Paid)<div class="row"><span class="min-w-0 flex-1 text-ink-muted">Чек отправлен</span><span class="text-right">{{ $mail }}</span></div>@endif
    </div>

    <div class="mt-auto flex flex-col items-center gap-1 pt-8 text-center text-xs text-ink-dim">
        <span>{{ $self->name }}@if ($self->inn), ИНН {{ $self->inn }}@endif</span>
        <span class="flex gap-3"><a href="/company" class="hover:text-ink">О компании</a><a href="/terms" class="hover:text-ink">Соглашение</a><a href="/privacy" class="hover:text-ink">Данные</a></span>
    </div>
</x-pickup.layout>
