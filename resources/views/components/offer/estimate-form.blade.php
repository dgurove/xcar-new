{{-- Оценка цены продажи менеджером (11.10.2026) — на месте цены и подтверждения, на телефоне листом по кнопке снизу
     (`DealPlacement`). Панели одна на виду (`estimate_edit_controller`):
     - form — как ввод суммы в Apple Pay: сумма крупно по центру, заявленная, полоса 48 часов (за 6 ч оранжевая, вышло —
       без полосы, красным), «Отправить»;
     - choose — корректировка (цена уже стоит, админ не уверен): крупно «Цена XCar», «Согласен» лаймом и «Предложить свою»,
       она открывает form;
     - done — ответил: галочка рисуется, сумма, когда отправлено, «Изменить».
     Отклонили — словом сверху, ответить снова, срок прежний. --}}
@props(['offer', 'estimate'])
@php
    // Заявленную менеджеру могли скрыть при просьбе (11.10.2026).
    $declared = $estimate->showsDeclared() ? $offer->declaredPrice() : null;
    $given = $estimate->isGiven();
    $correction = $estimate->isCorrection();
    $due = $estimate->due_at;
    $total = \App\Offers\Estimate::HOURS * 3600;
    $left = max(0, now()->diffInSeconds($due, false));
    $tone = match (true) { $due->isPast() => 'danger', $left <= \App\Offers\Estimate::REMIND_HOURS * 3600 => 'urgent', default => 'accent' };
    $open = match (true) { $errors->has('amount') => 'form', $given => 'done', $correction => 'choose', default => 'form' };
    $back = $given ? 'done' : ($correction ? 'choose' : null);
    $money = fn ($v) => \App\Support\Money::nums($v);
@endphp
<div class="estimate" data-controller="estimate-edit">
    @if ($estimate->rejected_at && ! $given)<x-ui.flash tone="urgent" class="mb-3 text-center">{{ $correction ? 'Вашу цену' : 'Оценку' }} {{ \App\Support\Money::rub($estimate->rejected_amount) }} отклонили</x-ui.flash>@endif

    @if ($given)
        <div data-estimate-edit-target="pane" data-pane="done" @if ($open !== 'done') hidden @endif>
            <div class="money-hero">
                <svg class="estimate-ok" viewBox="0 0 64 64" aria-hidden="true"><circle cx="32" cy="32" r="28"/><path d="M20 33l8 8 16-17"/></svg>
                <span class="money-hero-cap">{{ match (true) { $estimate->agreed() => 'Вы согласны с ценой', $correction => 'Ваша цена', default => 'Ваша оценка' } }}</span>
                <span class="money-hero-sum nums">{{ $money($estimate->amount) }} <small>₽</small></span>
                <span class="money-hero-state text-ink-muted">отправлено {{ $estimate->estimated_at?->translatedFormat($estimate->estimated_at->isToday() ? 'H:i' : 'j M, H:i') }}</span>
                @if ($correction && ! $estimate->agreed())<span class="money-hero-car nums">цена XCar {{ \App\Support\Money::rub($estimate->base_price) }}</span>
                @elseif ($declared)<span class="money-hero-car nums">заявленная {{ \App\Support\Money::rub($declared) }}</span>@endif
            </div>
            <button type="button" class="btn btn-quiet mt-5 w-full" data-action="estimate-edit#show" data-estimate-edit-pane-param="{{ $correction ? 'choose' : 'form' }}">Изменить</button>
        </div>
    @endif

    @if ($correction)
        <div data-estimate-edit-target="pane" data-pane="choose" @if ($open !== 'choose') hidden @endif>
            <div class="money-hero">
                <span class="money-hero-cap">Цена XCar</span>
                <span class="money-hero-sum nums">{{ $money($estimate->base_price) }} <small>₽</small></span>
                @if ($declared)<span class="money-hero-car mt-2 nums">заявленная {{ \App\Support\Money::rub($declared) }}</span>@endif
            </div>
            @include('components.offer.estimate-due')
            <form method="post" action="/offers/{{ $offer->number }}/estimate" class="mt-5">
                @csrf<input type="hidden" name="agree" value="1">
                <button type="submit" class="btn btn-accent w-full">Согласен</button>
            </form>
            <button type="button" class="btn btn-quiet mt-2 w-full" data-action="estimate-edit#show" data-estimate-edit-pane-param="form">Предложить свою</button>
            @if ($given)<button type="button" class="btn btn-quiet mt-2 w-full" data-action="estimate-edit#show" data-estimate-edit-pane-param="done">Отмена</button>@endif
        </div>
    @endif

    <form method="post" action="/offers/{{ $offer->number }}/estimate" class="flex flex-col" data-estimate-edit-target="pane" data-pane="form" @if ($open !== 'form') hidden @endif>
        @csrf
        <div class="money-hero">
            <label for="estimate-amount" class="money-hero-cap">{{ $correction ? 'Ваша цена' : 'Ваша оценка' }}</label>
            <span class="mt-1 flex items-baseline justify-center gap-1.5">
                <input id="estimate-amount" type="text" name="amount" required inputmode="decimal" enterkeyhint="send" autocomplete="off" placeholder="0" data-check-text="Укажите цену"
                    class="pay-amount estimate-amount nums" data-controller="digits" data-action="input->digits#format" data-estimate-edit-target="input"
                    value="{{ old('amount', $given && ! $estimate->agreed() ? $money($estimate->amount) : '') }}" @if ($open === 'form') autofocus @endif>
                <span class="text-2xl font-medium text-ink-muted">₽</span>
            </span>
            @error('amount')<span class="mt-2 text-sm text-danger">{{ $message }}</span>@enderror
            @if ($correction)<span class="money-hero-car mt-2 nums">цена XCar {{ \App\Support\Money::rub($estimate->base_price) }}</span>
            @elseif ($declared)<span class="money-hero-car mt-2 nums">заявленная {{ \App\Support\Money::rub($declared) }}</span>@endif
        </div>
        @unless ($given)@include('components.offer.estimate-due')@endunless
        <button type="submit" class="btn btn-accent mt-5 w-full">{{ $given ? 'Сохранить' : 'Отправить' }}</button>
        @if ($back)<button type="button" class="btn btn-quiet mt-2 w-full" data-action="estimate-edit#show" data-estimate-edit-pane-param="{{ $back }}">Отмена</button>@endif
    </form>
</div>
