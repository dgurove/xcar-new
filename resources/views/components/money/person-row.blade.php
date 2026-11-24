{{-- Строка страницы человека в «Оплатах» — сделка со всеми её счетами раздела, ручная ссылка без сделки или выплата
     (`Billing\MoneyRow`; 22.11.2026, владелец: «ВСЕ сделки, столбец „Статус“ — этап», «несколько поступлений по тачке — сумма, а
     не две строки»). Слева — значок типа, марка с годом; «Статус» — этап сделки (`MoneyStage`), «Оплата» — что с деньгами словом
     (`MoneyRows::state`, у оплаченной — как заплатили, у сделки без счёта — чего не хватает). Справа — сумма и сколько прошло с
     выставления («6д 5ч», срок прошёл — красным), у оплаченной — день оплаты. Кнопки строки (владелец: «без необходимости
     проваливаться внутрь»): «Выставить» — на месте даты, остальные — в «Оплате» за словом; «Оплатил наличными» в строке нет — в окне (23.11.2026, владелец: «убери из строк»); «Оплатил наличными» (без «Напомнить» — владелец 22.11.2026); «Пришли»; «Назначить менеджера»;
     «Выплатить». На телефоне — два этажа и одна главная кнопка во втором. Нажатие — окно машины рядом, без машины — карточка счёта;
     в XCAR Pay (22.11.2026) машина — шторкой со страницей CRM (`Support\DealSheet`): окно машины там не живёт, его действия — в CRM. --}}
@props(['table', 'row' => null, 'invoice' => null, 'tab' => null, 'attempts' => null, 'tried' => null])
@php
    use App\Support\Money; use App\Http\Admin\MoneyRows as R; use App\Billing\MoneyRow;
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
        $r->gap !== null => [$r->gap, $r->gap === R::gap('commission') ? 'text-accent-text' : 'text-ink-muted'],
        $unsent => [null, ''],
        // Подбор внутри счёта за ТС ПРАЙМ (раздел «Комиссии», 23.11.2026): ссылка и её состояние — у машины в «ДКП».
        // 24.11.2026, начальник: «как-то помечать» сумму, что не своей ссылкой, а частью счёта, — «в счёте 882 000 ₽».
        $r->inCarInvoice() => ['в счёте '.Money::rub($r->invoices->sum(fn ($i) => (float) $i->total)), 'text-ink-muted'],
        $first !== null => ($tried && $tried->has($first->id) && ($l = R::linkWord($first))) ? $l : R::state($first),
        default => [null, ''],
    };
    // Тревога оплаты на телефоне — вместо этапа: «сообщил», «не прошла», «открыл, не оплатил».
    $alarm = $word && (in_array($tone, ['text-urgent', 'text-danger'], true) || $r->inCarInvoice());
    $amount = $r->amount();
    $since = $r->since();
    // Дата выставления, а не сколько прошло (22.11.2026, начальник): «вчера», «07.10»; у оплаченной — день оплаты. Срок прошёл — красным.
    $sinceText = $since ? \App\Support\Ago::html($since) : null;
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
    $assign = $service && $admin && ! $r->service->manager_id && ! $r->service->credit_deal_id;
    // XCAR Pay: машина — шторкой поверх оплат, строку целиком ловит `deal_sheet_controller`.
    // Ручная ссылка (ключ `s{счёт}`) — своей карточкой рядом и с машиной: в ней назначение, копия ссылки, возврат.
    // С 22.11.2026 — «Оплата сделки» (начальник: «где редактор оплаты?»): только «Продажа», вся сделка — кнопкой в ней.
    $sheet = $offer && \App\Billing\PayView::here() && ! str_starts_with($r->key(), 's') ? \App\Support\DealSheet::money($offer, $r->deal) : null;
@endphp
@php ob_start(); @endphp
@switch($main)
    @case('issue')<x-deal.issue-button :deal="$deal" :invoice="$first"/>@break
    @case('claim')<form method="post" action="/work/payments/{{ $claim->id }}/confirm" class="contents" data-turbo-confirm="Поступила {{ Money::rub($claim->amount) }}?" data-turbo-confirm-text="Не поступила — откройте строку: там «Не поступила»">@csrf<button type="submit" class="btn btn-accent btn-xs">Пришли</button></form>@break
    @case('payout')<button type="button" class="btn btn-accent btn-xs" data-controller="emit" data-action="emit#send" data-emit-event-param="pay-{{ $r->service->id }}:open">Выплатить</button>@break
@endswitch
@php $button = trim(ob_get_clean()); @endphp
<tr @if ($sheet) data-deal-sheet-row data-row-key="{{ $r->key() }}" @else data-detail-key="{{ $r->key() }}" @endif data-search-row>
    @foreach ($table->columns as $key => $col)
        @switch($key)
        @case('title')
    <td class="{{ $col->classes() }}">
        {{-- Телефон: первый этаж — название и сумма, второй — этап (или тревога оплаты) и справа кнопка или сколько прошло
             (22.11.2026: под названием теперь этап, сумма невыставленной — тоже справа). --}}
        <x-cell.title :key="$r->key()" :offer="$offer" :title="$title" :category="$offer ? null : \App\Cars\Category::Passenger" :with="$tab ? ['tab' => $tab] : []" :sheet="$sheet">@if ($amount > 0)<span class="line-end sm:hidden nums {{ $paid ? 'text-open' : '' }}">{{ Money::rub($amount) }}</span>@endif</x-cell.title>
        <span class="cell-sub cell-sub--one sm:hidden">@if ($alarm)<span class="{{ $tone }}">{{ $word }}</span>@elseif ($r->stage)<span>{{ $r->stage }}</span>@elseif ($word)<span class="{{ $tone }}">{{ $word }}</span>@endif<span class="line-end">@if ($button){!! $button !!}@elseif ($sinceText)<span class="nums {{ $sinceTone }}">{{ $sinceText }}</span>@endif</span></span>
    </td>
        @break
        @case('vendor')
    <td class="{{ $col->classes() }}">@if ($offer)<x-vendor.ref :vendor-id="$offer->vendor_id" :ref="$offer->claim_ref" cell/>@endif</td>
        @break
        @case('stage')
    <td class="{{ $col->classes() }}">{{ $r->stage }}</td>
        @break
        @case('pay')
    {{-- Слово и кнопки строки (23.11.2026, владелец: кнопки — в «Оплате», не отдельным столбцом без заголовка; «Оплатил наличными» —
         не в строке, а в окне). --}}
    <td class="{{ $col->classes() }}"><span class="pay-cell">@if ($word)<span class="pay-word {{ $tone }}">{{ $word }}</span>@endif<span class="row-acts">
        @if ($main === 'claim' || $main === 'payout'){!! $button !!}@endif
        @if ($assign)<x-money.assign-sheet :invoice="$r->service"/>@endif
    </span></span></td>
        @break
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
    {{-- «Выставить» — здесь, на месте даты; без «Оплаты» с кнопками («Оплата за подбор») — и прочая главная кнопка. --}}
    @php $here = $main === 'issue' || ($button && ! $table->has('pay')); @endphp
    <td class="{{ $col->classes() }} {{ $here ? '' : $sinceTone }}">@if ($here){!! $button !!}@else{{ $sinceText }}@endif</td>
        @break
        @endswitch
    @endforeach
</tr>
@if ($main === 'payout')
    <tr hidden><td><div class="contents" data-controller="sheet" data-action="pay-{{ $r->service->id }}:open@window->sheet#open"><x-ui.sheet :id="'pay-'.$r->service->id" :title="'Выплата '.$r->service->party->name"><x-billing.pay-form :invoice="$r->service" :action="'/work/money/invoices/'.$r->service->id.'/payments'" sheet/></x-ui.sheet></div></td></tr>
@endif
