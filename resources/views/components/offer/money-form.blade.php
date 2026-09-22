{{-- Деньги сделки одной формой: цена подтверждения, закупочная, разница, поле
     «Агентское вознаграждение» с живым «нам остаётся» и режим — выплачиваем или
     менеджер удерживает сам. Та же форма принимает подтверждение и правит сделку. --}}
@props(['action', 'amount', 'cost' => null, 'commission' => null, 'mode' => \App\Offers\CommissionMode::Payout, 'submit' => 'Принять', 'confirm' => null, 'method' => 'post', 'id' => null, 'note' => null])
@php
    use App\Offers\CommissionMode;
    use App\Support\Money;
    $margin = $cost === null ? null : $amount - $cost;
    $current = old('commission', $commission);
    $id ??= 'commission-'.uniqid();
@endphp
<form method="post" action="{{ $action }}" class="flex flex-col gap-4" data-controller="commission" @if ($margin !== null) data-commission-margin-value="{{ $margin }}" @endif @if ($confirm) data-turbo-confirm="{{ $confirm }}" @endif>
    @csrf
    @if ($method !== 'post')@method($method)@endif
    {{ $slot }}
    <dl class="grid grid-cols-[auto_1fr] items-baseline gap-x-4 gap-y-1.5">
        <dt class="text-sm text-ink-dim">Цена подтверждения</dt><dd class="nums text-right font-medium">{{ Money::rub($amount) }}</dd>
        <dt class="text-sm text-ink-dim">Закупочная</dt><dd class="nums text-right">{{ $cost === null ? 'не указана' : Money::rub($cost) }}</dd>
        @if ($margin !== null)<dt class="text-sm text-ink-dim">Разница</dt><dd class="nums text-right font-semibold {{ $margin < 0 ? 'text-danger' : '' }}">{{ Money::rub($margin) }}</dd>@endif
    </dl>
    <div class="field">
        <label for="{{ $id }}" class="field-label">Агентское вознаграждение, ₽</label>
        <input type="hidden" name="commission" data-commission-target="amount" value="{{ $current }}">
        <input id="{{ $id }}" type="text" inputmode="numeric" class="field-input nums text-lg" data-commission-target="display" data-action="input->commission#input" value="{{ $current !== null && $current !== '' ? Money::nums((int) $current) : '' }}" autocomplete="off" placeholder="0">
        @error('commission')<p class="field-error">{{ $message }}</p>@enderror
    </div>
    @if ($margin !== null)
        <dl class="grid grid-cols-[auto_1fr] items-baseline gap-x-4">
            <dt class="text-sm text-ink-dim">Нам остаётся</dt><dd class="nums text-right text-lg font-semibold" data-commission-target="ours">{{ Money::rub($margin - (int) $current) }}</dd>
        </dl>
    @endif
    <div class="flex flex-wrap gap-2">
        @foreach (CommissionMode::cases() as $m)
            <label class="choice"><input type="radio" name="mode" value="{{ $m->value }}" @checked(old('mode', $mode->value) === $m->value)><span>{{ $m->label() }}</span></label>
        @endforeach
    </div>
    @error('mode')<p class="field-error">{{ $message }}</p>@enderror
    @if ($note ?? null)<p class="text-sm text-ink-muted">{{ $note }}</p>@endif
    <x-ui.button block>{{ $submit }}</x-ui.button>
</form>
