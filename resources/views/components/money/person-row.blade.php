{{-- Строка страницы человека в «Оплатах» (18.11.2026, владелец выбрал «два этажа, как „Предложения“»: «только кол-во дней и часов
     справа под суммой, под маркой статус»). Слева — значок типа, марка с годом, под ней что с оплатой одним словом (`MoneyRows::state`,
     у оплаченного — как заплатили). Справа — сумма и под ней сколько прошло с выставления («6д 5ч», срок прошёл — красным), у
     оплаченного — день оплаты. Кнопка — только где ход наш: «Выставить», «Пришли» (сообщил об оплате), «Выплатить»; у выставленного
     кнопок нет. На ПК — те же факты столбцами `MoneyController::personTable`, на телефоне — концами строк (`.line-end`). Нажатие —
     окно машины рядом; без машины — карточка счёта. --}}
@props(['table', 'invoice', 'tab', 'paid' => false, 'attempts' => null])
@php
    use App\Support\Money; use App\Http\Admin\MoneyRows as R;
    $i = $invoice;
    $offer = $i->carOffer();
    $title = $offer ? null : (R::what($i) ?? R::payoutKind($i));
    [$word, $tone] = $paid ? [R::paidWay($i, $attempts ?? collect()), 'text-ink-muted'] : R::state($i);
    $shown = $i->isShownToManager();
    $amount = $paid ? $i->total : $i->remaining();
    $since = $paid ? $i->paid_at?->format('d.m') : ($shown ? \App\Support\Ago::since($i->shown_at ?? $i->created_at) : null);
    $sinceTone = ! $paid && $i->isOverdue() ? 'text-danger' : 'text-ink-muted';
    $claim = $paid ? null : $i->claims->first();
    $deal = $i->deal;
    $act = match (true) {
        $paid => null,
        ! $shown && $deal => 'issue',
        (bool) $claim => 'claim',
        $i->isOwed() => 'payout',
        default => null,
    };
@endphp
@php ob_start(); @endphp
@switch($act)
    @case('issue')<x-deal.issue-button :deal="$deal" :invoice="$i"/>@break
    @case('claim')<form method="post" action="/work/payments/{{ $claim->id }}/confirm" class="contents" data-turbo-confirm="Поступила {{ Money::rub($claim->amount) }}?" data-turbo-confirm-text="Не поступила — откройте строку: там «Не поступила»">@csrf<button type="submit" class="btn btn-accent btn-xs">Пришли</button></form>@break
    @case('payout')<button type="button" class="btn btn-accent btn-xs" data-controller="emit" data-action="emit#send" data-emit-event-param="pay-{{ $i->id }}:open">Выплатить</button>@break
@endswitch
@php $button = trim(ob_get_clean()); @endphp
<tr @if ($offer) data-detail-key="{{ $offer->number }}" @endif data-search-row>
    @foreach ($table->columns as $key => $col)
        @switch($key)
        @case('title')
    <td class="{{ $col->classes() }}">
        @if ($offer)
            <x-cell.title :key="$offer->number" :offer="$offer" :with="['tab' => $tab]"><span class="line-end sm:hidden nums {{ $paid ? 'text-open' : '' }}">{{ Money::rub($amount) }}</span></x-cell.title>
        @else
            <a href="{{ \App\Http\Admin\MoneyController::url($i) }}" class="cell-title" data-turbo-frame="_top"><span class="cell-name">{{ $title }}</span><span class="line-end sm:hidden nums {{ $paid ? 'text-open' : '' }}">{{ Money::rub($amount) }}</span></a>
        @endif
        <span class="cell-sub cell-sub--one sm:hidden">@if ($word)<span class="{{ $tone }}">{{ $word }}</span>@endif<span class="line-end">@if ($button){!! $button !!}@elseif ($since)<span class="nums {{ $sinceTone }}">{{ $since }}</span>@endif</span></span>
    </td>
        @break
        @case('status')
    <td class="{{ $col->classes() }} {{ $tone }}">{{ $word }}</td>
        @break
        @case('amount')
    <td class="{{ $col->classes() }} phone-off {{ $paid ? 'text-open' : '' }}">{{ Money::nums($amount) }}</td>
        @break
        @case('since')
    <td class="{{ $col->classes() }} {{ $sinceTone }}">{{ $since }}</td>
        @break
        @case('action')
    <td class="{{ $col->classes() }}">{!! $button !!}</td>
        @break
        @endswitch
    @endforeach
</tr>
@if ($act === 'payout')
    <tr hidden><td><div class="contents" data-controller="sheet" data-action="pay-{{ $i->id }}:open@window->sheet#open"><x-ui.sheet :id="'pay-'.$i->id" :title="'Выплата '.$i->party->name"><x-billing.pay-form :invoice="$i" :action="'/work/money/invoices/'.$i->id.'/payments'" sheet/></x-ui.sheet></div></td></tr>
@endif
