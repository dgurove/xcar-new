{{-- Заработок менеджера за месяц (22.11.2026, начальник: «чтобы видели, сколько заработали»; владелец: по месяцу полной оплаты —
     `Billing\Earnings`): «Заработок», месяц пилюлей с меню прошлых месяцев и сумма. Накопленной суммы нет, только месяц. Ни разу
     ничего не заработал — куска нет. Один на экран менеджера (`/account/money`) и страницу человека в «Оплатах» CRM. --}}
@props(['user'])
@php
    use App\Billing\Earnings; use App\Support\Money; use Illuminate\Support\Carbon;
    $months = Earnings::months($user);
    $key = preg_match('~^\d{4}-\d{2}$~', (string) request('month')) ? (string) request('month') : now()->format('Y-m');
    $month = Carbon::createFromFormat('Y-m-d', $key.'-01')->startOfDay();
    $sum = $months[$key] ?? Earnings::of($user, $month);
    $list = collect($months)->put(now()->format('Y-m'), $months[now()->format('Y-m')] ?? 0.0)->put($key, $sum)->sortKeysDesc();
    $name = fn (string $k) => mb_strtolower(Carbon::createFromFormat('Y-m-d', $k.'-01')->translatedFormat($k === now()->format('Y-m') || str_starts_with($k, now()->format('Y')) ? 'F' : 'F Y'));
    $id = 'earned-'.$user->id;
@endphp
@if ($months->isNotEmpty() || $sum > 0)
    <div {{ $attributes->merge(['class' => 'earned']) }}>
        <span class="text-ink-muted">Заработок</span>
        <span class="contents" data-controller="menu">
            <button type="button" class="pill" data-action="menu#toggle" aria-haspopup="menu" aria-controls="{{ $id }}">{{ $name($key) }}<x-ui.icon name="chevron-down" class="ml-1 size-4"/></button>
            <span id="{{ $id }}" class="menu" popover data-menu-target="list" role="menu">
                @foreach ($list as $k => $v)
                    <a href="{{ request()->fullUrlWithQuery(['month' => $k === now()->format('Y-m') ? null : $k, 'page' => null]) }}" class="menu-item" role="menuitem" data-turbo-action="replace" data-action="menu#close" @if ($k === $key) aria-current="true" @endif><span class="flex-1">{{ $name($k) }}</span><span class="nums text-ink-muted">{{ Money::rub($v) }}</span></a>
                @endforeach
            </span>
        </span>
        <span class="nums font-semibold">{{ Money::rub($sum) }}</span>
    </div>
@endif
