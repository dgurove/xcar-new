{{-- Расчёты с менеджерами (06.10.2026, бывшие «Взаиморасчёты»: владелец прочёл голое число справа как «мы должны
     Бородину», а должен был он). Справа у каждого числа — кто кому: «должен нам до 8 окт» или «должны ему», оба —
     стопкой. Под именем — что горит: просрочил, сообщил об оплате. Суммы — строки его «Денег» (`ManagerLedger`).
     Строка ведёт в карточку менеджера на «Деньги». Менеджеры без денег не показываются. --}}
@php use App\Support\Money; @endphp
<x-ui.shell title="Расчёты с менеджерами" :back="['Оплаты', '/work/money']">
    @if ($managers->isNotEmpty())<x-ui.toolbar :sort="$sort" name="managers"/>@endif
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
                        @if ($p['overdue'] > 0 || $p['claimed'] > 0)
                            <span class="row-sub">
                                @if ($p['overdue'] > 0)<span class="nums whitespace-nowrap text-danger">просрочил {{ Money::rub($p['overdue']) }}</span>@endif
                                @if ($p['claimed'] > 0)<span class="nums whitespace-nowrap text-urgent">сообщил об оплате {{ Money::rub($p['claimed']) }}</span>@endif
                            </span>
                        @endif
                    </span>
                    <span class="flex shrink-0 flex-col items-end gap-1 text-right">
                        @if ($p['pay'] > 0)
                            <span><span class="nums block font-semibold">{{ Money::rub($p['pay']) }}</span><span class="block text-xs text-ink-dim">должен нам@if ($p['pay_due']) до {{ $p['pay_due']->translatedFormat('j M') }}@endif</span></span>
                        @endif
                        @if ($p['payout'] > 0)
                            <span><span class="nums block font-semibold text-accent-text">{{ Money::rub($p['payout']) }}</span><span class="block text-xs text-ink-dim">должны ему</span></span>
                        @endif
                        @if ($p['pay'] == 0 && $p['payout'] == 0)
                            <span><span class="nums block text-ink-muted">{{ Money::rub($p['paid_out']) }}</span><span class="block text-xs text-ink-dim">выплатили</span></span>
                        @endif
                    </span>
                    <x-ui.chevron/>
                </a>
            @endforeach
        </div>
    @endif
</x-ui.shell>
