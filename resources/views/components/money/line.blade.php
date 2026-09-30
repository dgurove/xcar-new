{{-- Строка денег в `.list`: кружок способа (тон — состояние), что это и одной строкой когда и как, справа сумма.
     Одна на кабинет, CRM и поступления. `acts` — кнопки под строкой (Поступило, Вернуть), `strike` — не поступило. --}}
@props(['icon', 'title', 'sub' => null, 'amount' => null, 'tone' => null, 'strike' => false, 'href' => null])
@php
    $amountClass = match ($tone) { 'urgent' => 'text-urgent', 'danger' => 'text-ink-dim line-through', 'muted' => 'text-ink-muted', default => '' };
    $tag = $href ? 'a' : 'div';
@endphp
<{{ $tag }} @if ($href) href="{{ $href }}" @endif {{ $attributes->class('row money-line') }}>
    <span class="money-ico {{ $tone ? 'money-ico--'.$tone : '' }}"><x-ui.icon :name="$icon"/></span>
    <span class="min-w-0 flex-1">
        <span class="block truncate {{ $strike ? 'text-ink-muted line-through' : '' }}">{{ $title }}</span>
        @if ($sub !== null && $sub !== '')<span class="row-sub">{{ $sub }}</span>@endif
    </span>
    @if ($amount !== null)<span class="nums shrink-0 {{ $amountClass }}">{{ \App\Support\Money::rub($amount) }}</span>@endif
    @isset($acts)<div class="money-acts">{{ $acts }}</div>@endisset
</{{ $tag }}>
