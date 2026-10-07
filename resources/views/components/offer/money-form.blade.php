{{-- Деньги сделки одной формой: цена подтверждения, закупочная, разница, поле
     «Агентское вознаграждение» с живым «нам остаётся» и режим — выплачиваем или
     менеджер удерживает сам. Та же форма принимает подтверждение и правит сделку.
     С `scheme` — ещё кому платят за ТС (05.10.2026): ПРАЙМ по счёту, страхователю по ДКП или страховой напрямую. У двух
     последних — сколько собственнику или страховой (меньше закупочной — взаимозачёт), нам — подбор (цена минус эта сумма
     минус вознаграждение), режим — удерживает сам.
     07.10.2026: суммы строками (.fields), кнопка .sheet-foot (форма всегда в шторке). --}}
@props(['action', 'amount', 'cost' => null, 'commission' => null, 'mode' => \App\Offers\CommissionMode::Payout, 'submit' => 'Принять', 'confirm' => null, 'method' => 'post', 'id' => null, 'note' => null,
    'scheme' => null, 'ownerPrice' => null])
@php
    use App\Offers\CommissionMode;
    use App\Offers\DealScheme;
    use App\Support\Money;
    $margin = $cost === null ? null : $amount - $cost;
    $current = old('commission', $commission);
    $id ??= 'commission-'.uniqid();
    $scheme = DealScheme::tryFrom((string) old('scheme', $scheme?->value ?? '')) ?? $scheme;
    // ДКП и «страховой напрямую»: покупатель платит не нам — сумма ему, нам подбор, вознаграждение удерживает менеджер.
    $dkp = (bool) $scheme?->paysSelection();
    $payee = $scheme?->payeeLabel() ?? DealScheme::OwnerDkp->payeeLabel();
    $owner = old('owner_price', $ownerPrice ?? $cost);
@endphp
<form method="post" action="{{ $action }}" class="flex flex-col gap-4" data-controller="commission" data-commission-amount-value="{{ $amount }}" @if ($cost !== null) data-commission-cost-value="{{ $cost }}" @endif
    @if ($margin !== null) data-commission-margin-value="{{ $margin }}" @endif @if ($confirm) data-turbo-confirm="{{ $confirm }}" @endif>
    @csrf
    @if ($method !== 'post')@method($method)@endif
    {{ $slot }}
    @if ($scheme)
        <div class="flex flex-col gap-1.5">
            <span class="field-label">За ТС платят</span>
            <div class="flex flex-wrap gap-2">
                @foreach (DealScheme::cases() as $s)
                    <label class="choice"><input type="radio" name="scheme" value="{{ $s->value }}" @checked($scheme === $s) data-action="commission#scheme" data-selection="{{ $s->paysSelection() ? 1 : 0 }}" data-payee="{{ $s->payeeLabel() }}"><span>{{ $s->label() }}</span></label>
                @endforeach
            </div>
        </div>
    @endif
    <dl class="grid grid-cols-[auto_1fr] items-baseline gap-x-4 gap-y-1.5">
        <dt class="text-sm text-ink-dim">Цена подтверждения</dt><dd class="nums text-right font-medium">{{ Money::rub($amount) }}</dd>
        <dt class="text-sm text-ink-dim">Закупочная</dt><dd class="nums text-right">{{ $cost === null ? 'не указана' : Money::rub($cost) }}</dd>
        @if ($margin !== null)<dt class="text-sm text-ink-dim" data-commission-target="oursOnly" @if ($dkp) hidden @endif>Разница</dt><dd class="nums text-right font-semibold {{ $margin < 0 ? 'text-danger' : '' }}" data-commission-target="oursOnly" @if ($dkp) hidden @endif>{{ Money::rub($margin) }}</dd>@endif
    </dl>
    <div class="fields">
        @if ($scheme)
            <div class="field @error('owner_price') field-invalid @enderror" data-commission-target="dkpOnly" @unless ($dkp) hidden @endunless>
                <label for="{{ $id }}-owner" class="field-label"><span data-commission-target="payee">{{ $payee }}</span>, ₽</label>
                <input type="hidden" name="owner_price" data-commission-target="ownerAmount" value="{{ $owner }}">
                <input id="{{ $id }}-owner" type="text" class="field-input nums text-lg" data-commission-target="ownerDisplay" data-action="input->commission#input" value="{{ $owner ? Money::nums((int) $owner) : '' }}" autocomplete="off">
                <p class="col-span-full pb-2 text-right text-sm text-ink-muted empty:hidden" data-commission-target="offset"></p>
                @error('owner_price')<p class="field-error">{{ $message }}</p>@enderror
            </div>
        @endif
        <div class="field @error('commission') field-invalid @enderror">
            <label for="{{ $id }}" class="field-label">Вознаграждение, ₽</label>
            <input type="hidden" name="commission" data-commission-target="amount" value="{{ $current }}">
            <input id="{{ $id }}" type="text" class="field-input nums text-lg" data-commission-target="display" data-action="input->commission#input" value="{{ $current !== null && $current !== '' ? Money::nums((int) $current) : '' }}" autocomplete="off" placeholder="0">
            @error('commission')<p class="field-error">{{ $message }}</p>@enderror
        </div>
    </div>
    @if ($margin !== null || $scheme)
        <dl class="grid grid-cols-[auto_1fr] items-baseline gap-x-4">
            <dt class="text-sm text-ink-dim" data-commission-target="oursLabel" data-ours="Нам остаётся" data-dkp="Менеджер платит нам">{{ $dkp ? 'Менеджер платит нам' : 'Нам остаётся' }}</dt>
            <dd class="nums text-right text-lg font-semibold" data-commission-target="ours">{{ $margin !== null ? Money::rub($margin - (int) $current) : '' }}</dd>
        </dl>
    @endif
    <div class="flex flex-wrap gap-2" data-commission-target="oursOnly" @if ($dkp) hidden @endif>
        @foreach (CommissionMode::cases() as $m)
            <label class="choice"><input type="radio" name="mode" value="{{ $m->value }}" @checked(old('mode', $mode->value) === $m->value)><span>{{ $m->label() }}</span></label>
        @endforeach
    </div>
    @error('mode')<p class="field-error">{{ $message }}</p>@enderror
    @if ($note ?? null)<p class="text-sm text-ink-muted">{{ $note }}</p>@endif
    <div class="sheet-foot"><x-ui.button block>{{ $submit }}</x-ui.button></div>
</form>
