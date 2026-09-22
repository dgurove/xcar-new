{{-- Операция строкой: слова, ТС, дата; сумма справа — приход ему зелёным, к оплате нам обычным, сообщённое серым. --}}
@php use App\Support\Money; $tone = match ($r['kind']) { 'payout', 'offset' => 'text-accent-text', 'claim' => 'text-ink-muted', 'rejected' => 'text-ink-muted line-through', default => '' }; @endphp
<a href="{{ $r['href'] }}" class="row !py-3">
    <span class="min-w-0 flex-1">
        <span class="block truncate {{ $r['kind'] === 'rejected' ? 'text-ink-muted' : '' }}">{{ $r['title'] }}</span>
        <span class="row-sub"><span class="tag nums">{{ $r['at']->translatedFormat('j M Y') }}</span>@if ($r['offer'])<span class="tag truncate">{{ $r['offer'] }}</span>@endif</span>
    </span>
    <span class="nums shrink-0 font-medium {{ $tone }}">{{ Money::rub($r['amount']) }}</span>
</a>
