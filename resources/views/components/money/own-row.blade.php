{{-- Строка «Оплат» менеджера (`/account/money`, 22.11.2026; тот же экран в XCAR Pay) — сделка со всеми его отправленными счетами
     раздела (`Billing\MoneyRow`, `MoneyBook` своим режимом). «Статус» — что по сделке его словами, как в «Сделках» сайта: его дело
     зелёным (вышел срок — красным), наш ход — «На согласовании» / «В обработке» (`Offers\Turn`; этапы маршрута — наши, ему всё
     равно). «Оплата» — что с деньгами: срок, «Ждёт подтверждения», «Оплата не прошла», у оплаченной — как заплатили; «Готовим
     счёт» говорит заголовок группы. Вознаграждение — его по сделке, справа — когда выставили и сколько. Нажатие — его сделка
     (`#money`: «Оплатить», ссылка, «Сообщить об оплате» — там), гаражная — машина в гараже; в XCAR Pay — шторкой поверх. --}}
@props(['table', 'row', 'attempts' => null])
@php
    use App\Support\Money; use App\Support\Ago; use App\Http\Admin\MoneyRows as R; use App\Offers\Turn; use App\Users\Wording;
    use App\Billing\Acquiring\PayLinkState;
    $r = $row;
    $deal = $r->deal;
    $offer = $deal->offer;
    $first = $r->first();
    $paid = $r->isPaid();
    $me = auth()->user();
    $pay = \App\Billing\PayView::here();
    $path = $deal->href().($deal->isGarage() ? '' : '#money');
    $href = $pay && ! str_starts_with($path, 'http') ? \App\Support\Surface::Site->url($path) : $path;
    // Что по сделке — его словами (как `x-deal.site-table-row`).
    $task = $deal->isActive() ? $deal->managerTask() : null;
    $turn = $deal->isLive() ? Turn::of($deal, null, $task, list: true) : null;
    [$state, $stateTone] = match (true) {
        $deal->cancelled_at !== null => ['Сделка отменена', 'text-ink-muted'],
        $deal->closed_at !== null => ['Сделка закрыта', 'text-ink-muted'],
        $task !== null => [$turn?->mine ? $turn->title : Wording::mine($task['title']), $task['due']?->isPast() ? 'text-danger' : 'text-accent-text'],
        $turn !== null => [$turn->title, 'text-ink-muted'],
        default => ['Идёт работа', 'text-ink-muted'],
    };
    $claim = $paid ? null : $r->claim();
    $failed = $first && $first->payLinks->contains(fn ($l) => $l->state === PayLinkState::Open && ($l->error_at || $l->attempts->contains('status', 'canceled')));
    $byBuyer = $first && $first->party_id && $first->party_id !== $me->party_id;
    [$word, $tone] = match (true) {
        $paid => [R::paidWays($r->invoices, $attempts ?? collect()), 'text-ink-muted'],
        $r->invoices->isEmpty() || ! $first => [null, ''],
        (bool) $claim => ['Ждёт подтверждения', 'text-ink-muted'],
        $failed => ['Оплата не прошла', 'text-danger'],
        $r->isOverdue() => ['Просрочено', 'text-danger'],
        $byBuyer => ['Платит покупатель', 'text-ink-muted'],
        (bool) $first->due_at => ['до '.$first->due_at->format('d.m'), 'text-ink-muted'],
        default => [null, ''],
    };
    // Тревога оплаты на телефоне — вместо статуса сделки.
    $alarm = $word && $tone === 'text-danger';
    $amount = $r->amount();
    $since = $r->since();
    $sinceText = $since ? Ago::html($since) : null;
    $sinceTone = ! $paid && $r->isOverdue() ? 'text-danger' : 'text-ink-muted';
    $commission = (int) $deal->commission;
@endphp
<tr @if ($pay) data-deal-sheet-row data-row-key="{{ $deal->id }}" @else data-detail-key="{{ $deal->id }}" @endif data-search-row>
    @foreach ($table->columns as $key => $col)
        @switch($key)
        @case('title')
    <td class="{{ $col->classes() }}">
        <a href="{{ $href }}" class="row-link" @if ($pay) data-deal-sheet data-turbo="false" @endif><span class="cell-title"><x-ui.cat-icon :category="$offer->category()"/><span class="cell-name">{{ $offer->title() }}@if ($year = $offer->carRecord()->year)<span class="cell-year"> {{ $year }}</span>@endif</span>@if ($amount > 0)<span class="line-end sm:hidden nums {{ $paid ? 'text-open' : '' }}">{{ Money::rub($amount) }}</span>@endif</span></a>
        <span class="cell-sub cell-sub--one sm:hidden">@if ($alarm)<span class="{{ $tone }}">{{ $word }}</span>@else<span class="{{ $stateTone }}">{{ $state }}</span>@endif @if ($sinceText)<span class="line-end nums {{ $sinceTone }}">{{ $sinceText }}</span>@endif</span>
    </td>
        @break
        @case('state')
    <td class="{{ $col->classes() }}"><span class="{{ $stateTone }} whitespace-normal">{{ $state }}</span></td>
        @break
        @case('pay')
    <td class="{{ $col->classes() }} {{ $tone }}">{{ $word }}</td>
        @break
        @case('commission')
    <td class="{{ $col->classes() }}">@if ($commission > 0){{ Money::nums($commission) }}@endif</td>
        @break
        @case('since')
    <td class="{{ $col->classes() }} {{ $sinceTone }}">{{ $sinceText }}</td>
        @break
        @case('amount')
    <td class="{{ $col->classes() }} phone-off {{ $paid ? 'text-open' : '' }}">@if ($amount > 0){{ Money::nums($amount) }}@endif</td>
        @break
        @endswitch
    @endforeach
</tr>
