{{-- Строка «Ваш ход» в «Сделках» CRM (27.10.2026, владелец: «не видно, что нужно сделать, а что просто просрочено»): что
     сделать — заголовок группы, здесь машина, вендор с убытком, менеджер, сколько ждёт числом (серым; срок вышел — красным,
     без слова «просрочено») и кнопка дела. Рыжего нет: вся группа — наш ход. Столбцы — `DealController::table('us')`. --}}
{{-- debt — долг сделки во вкладке «Менеджер должен нам» (`DealDebt`, 24.11.2026, владелец: «непонятно сколько и за что»):
     столбцы «За что», «Должен», «Срок» вместо «Ждёт»; на телефоне под названием — «за ТС 620 000 ₽», справа — срок цветом или кнопка. --}}
@props(['deal', 'todo', 'table', 'debt' => null])
@php
    $offer = $deal->offer;
    $position = $offer->position();
    $late = $position->isOverdue();
    // Доп. просьба держит сделку (18.11.2026): ждём — с начала её шага (просьба ушла, показали менеджеру).
    $ask = $table->has('asked') ? \App\Offers\ExtraAsk::holding($deal) : null;
    $at = $late ? $position->deadline_at : ($todo['since'] ?? ($ask ? ($ask->shown_at ?? $ask->answered_at ?? $ask->since_at) : null));
    // Сколько ждёт — числом, без слов (17.11.2026, владелец: «просрочено я бы не писал — просто красным»): срок вышел — красным,
    // с момента срока; иначе серым, с начала дела. Что значит число — подсказкой.
    $wait = $at ? '<span class="nums" title="'.($late ? 'Просрочено на' : 'Ждёт').'" data-controller="timer" data-timer-since-value="'.$at->toIso8601String().'" data-timer-coarse-value="true"></span>' : null;
    $tone = $late ? 'text-danger' : 'text-ink-muted';
    // На телефоне время — в самой кнопке (`x-deal.todo-button wait`, 13.11.2026): строка в две строки. Без кнопки или у номера
    // телефона — по-прежнему строкой под названием.
    $inButton = $at && $todo['button'] && ! isset($todo['button']['tel']);
    // Гаражная (16.11.2026, владелец: «гараж — та же сделка с теми же этапами»): пока маршрут идёт, она во вкладке этапа — у
    // названия словом «гараж»; что с машиной у менеджера («подготовка») здесь не пишем (17.11.2026, владелец: «почему подготовка,
    // если переоценка») — это в окне.
    $garage = $deal->garageCar?->state;
    // Красный таймер срока обязывающего — у номера убытка, как во всех строках (18.11.2026, владелец: «не во всех вкладках»).
    $burning = \App\Offers\Binding::matters($offer) && \App\Offers\Binding::panics($offer);
@endphp
<tr data-detail-key="{{ $deal->offer->number }}" data-search-row id="deal-{{ $deal->id }}">
    @foreach ($table->columns as $key => $col)
        @switch($key)
        @case('title')
    <td class="{{ $col->classes() }}">
        <x-cell.title :key="$deal->offer->number" :offer="$offer">@if ($garage)<span class="ms-1.5 whitespace-nowrap text-sm text-ink-muted">гараж</span>@endif</x-cell.title>
        @if ($table->has('owed'))
        {{-- Телефон: под названием — за что и срок цветом; сумма или кнопка — справа (ячейка «Должен»). --}}
        <span class="cell-sub sm:hidden">@if ($debt){{ $debt->what ?: 'долг' }}@if (empty($todo['issue']) && empty($todo['claim'])) <span class="inline-block whitespace-nowrap {{ $debt->dueTone() }}">{{ $debt->dueWord() }}</span>@else <span class="nums">{{ \App\Support\Money::rub($debt->owed) }}</span>@endif @endif</span>
        @else
        <span class="cell-sub sm:hidden">@if ($ask){{ \Illuminate\Support\Str::ucfirst($ask->phrase()) }}@else<x-vendor.ref :vendor-id="$offer->vendor_id" :ref="$offer->claim_ref">@if ($burning)<x-offer.binding :offer="$offer" icon/>@endif</x-vendor.ref>@endif{{ "" }}@if ($wait && ! $inButton) <span class="inline-block whitespace-nowrap {{ $tone }}">{!! $wait !!}</span>@endif</span>
        @endif
    </td>
        @break
        @case('vendor')
    <td class="{{ $col->classes() }}"><x-vendor.ref :vendor-id="$offer->vendor_id" :ref="$offer->claim_ref" cell>@if ($burning)<x-offer.binding :offer="$offer" icon fit/>@endif</x-vendor.ref></td>
        @break
        @case('asked')
    <td class="{{ $col->classes() }}">{{ $ask ? \Illuminate\Support\Str::ucfirst($ask->phrase()) : '' }}</td>
        @break
        @case('manager')
    <td class="{{ $col->classes() }}"><x-cell.person :user="$deal->buyer" from="lg"/></td>
        @break
        @case('what')
    <td class="{{ $col->classes() }} {{ $debt ? '' : 'text-ink-dim' }}" @if ($debt) title="{{ $debt->label() }}" @endif>{{ $debt?->label() ?? '—' }}</td>
        @break
        @case('owed')
    {{-- Просроченная часть — красным; весь долг просрочен — всё число красным. На телефоне у строк «Выставить ссылку» и
         «Подтвердить оплату» здесь кнопка (на ПК она на месте срока). --}}
    @php($act = ! empty($todo['issue']) || ! empty($todo['claim']))
    <td class="{{ $col->classes() }} {{ $debt && $debt->overdue >= $debt->owed && $debt->late() ? 'text-danger' : '' }}">@if ($act)<span class="sm:hidden"><x-deal.todo-pay-action :deal="$deal" :todo="$todo"/></span>@endif<span @class(['max-sm:hidden' => $act])>@if ($debt){{ \App\Support\Money::rub($debt->owed) }}@if ($debt->late() && $debt->overdue < $debt->owed)<span class="cell-sub text-danger">просрочено {{ \App\Support\Money::rub($debt->overdue) }}</span>@endif @endif</span></td>
        @break
        @case('due')
    {{-- «Менеджер должен нам» (24.11.2026, вместе с «Оплатой за подбор»): наш ход — кнопкой на месте срока, как было в «Оплате за
         подбор»: «Выставить» неотправленный подбор, «Пришли» — менеджер сообщил об оплате. --}}
    @php($claim = $todo['claim'] ?? null)
    <td class="{{ $col->classes() }} whitespace-nowrap {{ empty($todo['issue']) && ! $claim ? $debt?->dueTone() : '' }}">@if (! empty($todo['issue']) || $claim)<x-deal.todo-pay-action :deal="$deal" :todo="$todo"/>@else{{ $debt?->dueWord() }}@endif</td>
        @break
        @case('wait')
    <td class="{{ $col->classes() }} whitespace-nowrap {{ $tone }}">{!! $wait !!}</td>
        @break
        @case('action')
    <td class="{{ $col->classes() }}">@if ($todo['button'])<x-deal.todo-button :button="$todo['button']" :wait="$inButton ? $at : null" :late="$late"/>@endif</td>
        @break
        @endswitch
    @endforeach
</tr>
