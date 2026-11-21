{{-- Строка страницы человека в «Оплатах» — сделка со всеми её счетами раздела, ручная ссылка без сделки или выплата
     (`Http\Admin\MoneyRow`; 22.11.2026, владелец: «ВСЕ сделки, столбец „Статус“ — этап», «несколько поступлений по тачке — сумма, а
     не две строки»). Слева — значок типа, марка с годом; «Статус» — этап сделки (`MoneyStage`), «Оплата» — что с деньгами словом
     (`MoneyRows::state`, у оплаченной — как заплатили, у сделки без счёта — чего не хватает). Справа — сумма и сколько прошло с
     выставления («6д 5ч», срок прошёл — красным), у оплаченной — день оплаты. На ПК — кнопки строки (владелец: «без необходимости
     проваливаться внутрь»): «Выставить» и «Оплатил наличными»; «Напомнить» и «Оплатил наличными»; «Пришли»; «Назначить менеджера»;
     «Выплатить». На телефоне — два этажа и одна главная кнопка во втором. Нажатие — окно машины рядом, без машины — карточка счёта. --}}
@props(['table', 'row' => null, 'invoice' => null, 'tab' => null, 'attempts' => null, 'tried' => null])
@php
    use App\Support\Money; use App\Http\Admin\MoneyRows as R; use App\Http\Admin\MoneyRow;
    // Строка одним счётом — вкладка «Оплата за подбор» (`SelectionController`): столбцы «Статус» (что с оплатой) и «Менеджер».
    $r = $row ?? ($invoice->deal ? MoneyRow::deal($invoice->deal, collect([$invoice])) : MoneyRow::service($invoice));
    $offer = $r->offer();
    $first = $r->first();
    $paid = $r->isPaid();
    $payout = $r->service?->isOwed() ?? false;
    $service = $r->service && ! $payout;
    $unsent = $r->unsent()->isNotEmpty();
    $deal = $r->deal;
    // Что с деньгами одним словом: оплачено — как; счёта нет — чего не хватает; не выставлен — ничего (это и есть кнопка и группа);
    // ждём — что со ссылкой или заявкой; по ссылке пытались и не вышло — её слово.
    [$word, $tone] = match (true) {
        $paid => [R::paidWays($r->invoices, $attempts ?? collect()), 'text-ink-muted'],
        $r->gap !== null => [$r->gap, 'text-ink-muted'],
        $unsent => [null, ''],
        $first !== null => ($tried && $tried->has($first->id) && ($l = R::linkWord($first))) ? $l : R::state($first),
        default => [null, ''],
    };
    // Тревога оплаты на телефоне — вместо этапа: «сообщил», «не прошла», «открыл, не оплатил».
    $alarm = $word && in_array($tone, ['text-urgent', 'text-danger'], true);
    $amount = $r->amount();
    $since = $r->since();
    $sinceText = $paid ? $since?->format('d.m') : ($since ? \App\Support\Ago::since($since) : null);
    $sinceTone = ! $paid && $r->isOverdue() ? 'text-danger' : 'text-ink-muted';
    $claim = $paid ? null : $r->claim();
    $title = $offer ? null : ($payout ? R::payoutKind($r->service) : (R::what($r->service) ?? 'Ссылка на оплату'));
    $admin = auth()->user()?->isAdmin();
    // Главная кнопка строки (телефон): наш ход.
    $main = match (true) {
        $paid => null,
        $unsent && $deal && $first => 'issue',
        (bool) $claim => 'claim',
        $payout => 'payout',
        default => null,
    };
    // Наличными у ручной ссылки — только у назначенной (в «Без менеджера» кнопка строки одна: «Назначить менеджера»).
    $cash = ! $paid && ! $payout && ! $claim && $first && ! ($service && ! $r->service->manager_id);
    $nudge = ! $paid && ! $unsent && ! $claim && $deal && $first && $first->isShownToManager();
    $assign = $service && $admin && ! $r->service->manager_id;
@endphp
@php ob_start(); @endphp
@switch($main)
    @case('issue')<x-deal.issue-button :deal="$deal" :invoice="$first"/>@break
    @case('claim')<form method="post" action="/work/payments/{{ $claim->id }}/confirm" class="contents" data-turbo-confirm="Поступила {{ Money::rub($claim->amount) }}?" data-turbo-confirm-text="Не поступила — откройте строку: там «Не поступила»">@csrf<button type="submit" class="btn btn-accent btn-xs">Пришли</button></form>@break
    @case('payout')<button type="button" class="btn btn-accent btn-xs" data-controller="emit" data-action="emit#send" data-emit-event-param="pay-{{ $r->service->id }}:open">Выплатить</button>@break
@endswitch
@php $button = trim(ob_get_clean()); @endphp
<tr data-detail-key="{{ $r->key() }}" data-search-row>
    @foreach ($table->columns as $key => $col)
        @switch($key)
        @case('title')
    <td class="{{ $col->classes() }}">
        {{-- Телефон: первый этаж — название и сумма, второй — этап (или тревога оплаты) и справа кнопка или сколько прошло
             (22.11.2026: под названием теперь этап, сумма невыставленной — тоже справа). --}}
        <x-cell.title :key="$r->key()" :offer="$offer" :title="$title" :category="$offer ? null : \App\Cars\Category::Passenger" :with="$tab ? ['tab' => $tab] : []">@if ($amount > 0)<span class="line-end sm:hidden nums {{ $paid ? 'text-open' : '' }}">{{ Money::rub($amount) }}</span>@endif</x-cell.title>
        <span class="cell-sub cell-sub--one sm:hidden">@if ($alarm)<span class="{{ $tone }}">{{ $word }}</span>@elseif ($r->stage)<span>{{ $r->stage }}</span>@elseif ($word)<span class="{{ $tone }}">{{ $word }}</span>@endif<span class="line-end">@if ($button){!! $button !!}@elseif ($sinceText)<span class="nums {{ $sinceTone }}">{{ $sinceText }}</span>@endif</span></span>
    </td>
        @break
        @case('stage')
    <td class="{{ $col->classes() }}">{{ $r->stage }}</td>
        @break
        @case('pay')
        @case('status')
    <td class="{{ $col->classes() }} {{ $tone }}">{{ $word }}</td>
        @break
        @case('manager')
    <td class="{{ $col->classes() }}"><x-cell.person :user="$r->deal?->buyer ?? $r->service?->manager()" from="lg"/></td>
        @break
        @case('amount')
    <td class="{{ $col->classes() }} phone-off {{ $paid ? 'text-open' : '' }}">@if ($amount > 0){{ Money::nums($amount) }}@endif</td>
        @break
        @case('since')
    {{-- Без столбца кнопок («Оплата за подбор») главная кнопка — здесь, на месте времени. --}}
    <td class="{{ $col->classes() }} {{ $button && ! $table->has('acts') ? '' : $sinceTone }}">@if ($button && ! $table->has('acts')){!! $button !!}@else{{ $sinceText }}@endif</td>
        @break
        @case('acts')
    {{-- ПК: кнопки строки рядом, без захода в окно (22.11.2026). --}}
    <td class="{{ $col->classes() }}"><span class="row-acts">
        @if ($main === 'issue' || $main === 'claim' || $main === 'payout'){!! $button !!}@endif
        @if ($nudge)<x-deal.nudge-sheet :deal="$deal" :invoice="$first" label="Напомнить" small/>@endif
        @if ($cash)
            <span class="contents" data-controller="sheet">
                <button type="button" class="btn btn-quiet btn-xs" data-action="sheet#open" aria-controls="cash-{{ $first->id }}">Оплатил наличными</button>
                <x-ui.sheet :id="'cash-'.$first->id" :title="'Наличными '.($offer ? $offer->title() : $title)">
                    <x-billing.pay-form :invoice="$first" :action="'/work/money/invoices/'.$first->id.'/payments'" :sources="['cash' => 'Наличные']" source="cash" sheet/>
                </x-ui.sheet>
            </span>
        @endif
        @if ($assign)<x-money.assign-sheet :invoice="$r->service"/>@endif
    </span></td>
        @break
        @endswitch
    @endforeach
</tr>
@if ($main === 'payout')
    <tr hidden><td><div class="contents" data-controller="sheet" data-action="pay-{{ $r->service->id }}:open@window->sheet#open"><x-ui.sheet :id="'pay-'.$r->service->id" :title="'Выплата '.$r->service->party->name"><x-billing.pay-form :invoice="$r->service" :action="'/work/money/invoices/'.$r->service->id.'/payments'" sheet/></x-ui.sheet></div></td></tr>
@endif
