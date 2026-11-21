{{-- Человек в «Оплатах» строкой (18.11.2026, владелец: «максимально простые красивые строки — аватарка, имя менеджера, справа сумма
     долга и стрелочка >»): под именем — только то, что требует нас, словами-элементами («2 отправить», «сообщил об оплате N ₽»,
     «ссылка не прошла», «просрочил N ₽» красным, у выплат — «реквизитов нет»); справа — сколько открытого в разделе, без подписи.
     Ведёт на его сделки (`MoneyController::person`). person — строка `MoneyController::people`. --}}
@props(['person', 'tab'])
@php use App\Support\Money; $p = $person; $payout = $tab === 'payouts'; @endphp
<a href="{{ \App\Http\Admin\MoneyController::personUrl($p['user'], $tab) }}" class="row money-person">
    @if ($p['user'])
        <x-ui.avatar :user="$p['user']" :size="30"/>
    @else
        <span class="flex size-[30px] shrink-0 items-center justify-center rounded-full bg-surface-3 text-ink-muted"><x-ui.icon name="users" class="size-4"/></span>
    @endif
    <span class="min-w-0 flex-1">
        <span class="block truncate">{{ $p['user']?->name ?? ($payout ? 'Другие получатели' : 'Без менеджера') }}</span>
        @if ($p['send'] || $p['claimed'] > 0 || $p['tried'] || $p['overdue'] > 0 || $p['noDetails'])
            <span class="row-sub">
                @if ($p['send'])<span class="whitespace-nowrap text-accent-text">{{ $p['send'] }} отправить</span>@endif
                @if ($p['claimed'] > 0)<span class="nums whitespace-nowrap text-urgent">сообщил об оплате {{ Money::rub($p['claimed']) }}</span>@endif
                @if ($p['tried'])<span class="whitespace-nowrap text-urgent">ссылка не прошла</span>@endif
                @if ($p['overdue'] > 0)<span class="nums whitespace-nowrap text-danger">{{ $payout ? 'просрочили' : 'просрочил' }} {{ Money::rub($p['overdue']) }}</span>@endif
                @if ($p['noDetails'])<span class="whitespace-nowrap text-danger">реквизитов нет</span>@endif
            </span>
        @endif
    </span>
    {{-- Подписи под суммой нет (18.11.2026, владелец: «должен» под каждой суммой — бред): кто кому — говорит раздел. --}}
    {{-- Всё оплачено — суммы нет (22.11.2026: человек в списке ради истории, «Оплаченных» больше нет). --}}
    @if ($p['sum'] > 0)<span @class(['nums shrink-0 font-semibold', 'text-accent-text' => $payout])>{{ Money::rub($p['sum']) }}</span>@endif
    <x-ui.chevron/>
</a>
