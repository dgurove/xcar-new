{{-- Взаиморасчёты по менеджерам: кто сколько должен нам, кому должны мы, просрочка и заявки — строка ведёт в
     карточку менеджера на «Деньги». Менеджеры без денег не показываются. --}}
@php use App\Support\Money; @endphp
<x-ui.shell title="Взаиморасчёты" :back="['Деньги', '/work/money']">
    @if ($managers->isEmpty())
        <x-ui.empty class="mt-6">Расчётов с менеджерами ещё не было</x-ui.empty>
    @else
        <div class="list mt-2">
            @foreach ($managers as $m)
                @php $p = $m['position']; @endphp
                <a href="/settings/users/{{ $m['user']->id }}?pill=money" class="row">
                    <x-ui.avatar :user="$m['user']" :size="36"/>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate">{{ $m['user']->name }}</span>
                        <span class="row-sub">
                            @if ($p['overdue'] > 0)<span class="nums text-danger">просрочено {{ Money::rub($p['overdue']) }}</span>@endif
                            @if ($p['claimed'] > 0)<span class="nums text-urgent">сообщил об оплате {{ Money::rub($p['claimed']) }}</span>@endif
                            @if ($p['payout'] > 0)<span class="nums text-urgent">мы должны {{ Money::rub($p['payout']) }}</span>@endif
                            @if ($p['pay'] == 0 && $p['payout'] == 0)<span class="nums">выплачено {{ Money::rub($p['paid_out']) }}</span>@endif
                        </span>
                    </span>
                    @if ($p['pay'] > 0)<span class="nums shrink-0">{{ Money::rub($p['pay']) }}</span>@endif
                    <x-ui.icon name="chevron-right" class="size-5 shrink-0 text-ink-dim"/>
                </a>
            @endforeach
        </div>
    @endif
</x-ui.shell>
