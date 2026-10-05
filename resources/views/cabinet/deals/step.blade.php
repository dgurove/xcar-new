{{-- Задача сделки — одна карточка на страницу сделки и карточку машины в гараже, пока она ждёт страховую (гаражная
     сделка идёт по тому же маршруту). Данные — `Cabinet\DealController::stepData`. Порядок как у задачи в приложении
     (05.10.2026, владелец): что сделать → где и у кого → ответы страховой (с кем связаться, что прислали) → действия.
     Пояснений нет (владелец: «зачем эссе в интерфейсе»): заголовок просьбы и сам инструмент — на шаге оплаты счёт со
     ссылкой и «Оплатить» шторкой прямо здесь; тексты этапов маршрута менеджеру не рисуются, они для уведомлений.
     Вне оплаты счетов здесь нет — они в «Расчёте»; гараж, где «Расчёта» нет, передаёт `withInvoices`. Путь —
     `cabinet.deals.path`. --}}
@php
    use App\Offers\DealState;
    use App\Workflow\Asks;
    // Шаг «оплатите счёт»: оплата тут же — счёт, ссылка, «Оплатить» шторкой (`x-billing.pay-sheet`).
    $payStep = $requirement && $position?->stage->isPayStep();
    $unpaid = $invoices->filter(fn ($i) => ! $i->isOwed() && $i->state === \App\Billing\InvoiceState::Issued);
    // Оплата, а счёта ещё нет: ход наш (`Position::awaitsInvoice`), просить оплатить нечего — счёт готовим.
    $noInvoice = (bool) $position?->awaitsInvoice();
    // ПРАЙМ без покупателя — ход менеджера: счёт встанет сам, как только он укажет, кому (`Deal::invoiceGap`).
    $needsBuyer = $deal->isActive() && $position?->stage->isPayStep() && $deal->hasContract() && $deal->invoiceGap() === 'buyer';
    // Строки счетов — только в гараже, где «Расчёта» нет; на странице сделки счёт в «Расчёте», а здесь — сама оплата.
    // На шаге оплаты — только сама оплата (ссылка и «Оплатить N ₽»), строка счёта повторяла бы ту же сумму.
    $stepInvoices = ($withInvoices ?? false) && ! $payStep ? $unpaid : collect();
    $claimable = $payStep ? $unpaid->filter(fn ($i) => $i->kind !== \App\Billing\ChargeKind::Reward && $i->remaining() - $i->claimed() > 0)->values() : collect();
    // Вернули на оплату («Оплата не поступила») — менеджер видит почему, пока не сообщил об оплате заново.
    $rejected = $payStep && $unpaid->every(fn ($i) => $i->claimed() == 0)
        ? \App\Billing\Payment::whereIn('invoice_id', $unpaid->pluck('id'))->where('state', \App\Billing\PaymentState::Rejected)->latest('id')->first() : null;
    $handover ??= null;
    $showHandover = $handover?->shows();
    // Поля шага, что показаны в получении (контакт, адрес, дата), второй раз в шаге не пишем.
    $payload = collect($position?->payload ?? [])->except($handover?->open ? $handover->keys : []);
    // Ответы — перепиской, по порядку писем: сначала контакт, потом документы. Много — ранние под «Ещё».
    $replies = ($replies ?? collect())->reverse()->values();
    $early = $replies->count() > 3 ? $replies->slice(0, $replies->count() - 2) : collect();
    $late = $replies->slice($early->count());
@endphp
<div class="flex flex-col gap-6">
    @if ($deal->state !== DealState::Active)
        {{-- Закончилась — состояние словом с датой, без своей плашки. --}}
        <p class="max-lg:hidden"><span class="font-medium {{ $deal->state === DealState::Done ? 'text-open' : 'text-danger' }}">{{ $deal->state->label() }}</span>@if ($deal->closed_at) <span class="nums text-sm text-ink-muted">{{ $deal->closed_at->translatedFormat('j M Y, H:i') }}</span>@endif</p>
    @endif

    @if ($deal->state === DealState::Active && $position)
        <div class="box {{ $requirement && $position->isOverdue() ? 'box-urgent' : '' }}">
            <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                <h2>{{ $position->stage->block?->name ?? 'Идёт работа' }}</h2>
                @if ($noInvoice)<p class="text-sm text-ink-muted">ждём нас</p>@else<x-route.clock :position="$position"/>@endif
            </div>

            @if ($needsBuyer)
                <h3 class="mt-5 text-lg">Укажите покупателя</h3>
            @elseif ($requirement)
                <h3 class="mt-5 text-lg">{{ $requirement->title }}</h3>
                @if ($rejected)<p class="mt-2 font-medium text-urgent">Оплата <span class="nums">{{ \App\Support\Money::rub($rejected->amount) }}</span> от <span class="nums">{{ $rejected->paid_at->translatedFormat('j M') }}</span> не поступила{{ $rejected->reject_reason ? ': '.$rejected->reject_reason : '' }}</p>@endif
                {{-- Срок просьбы — тот же, что часы в шапке шага: второй раз его не пишем. --}}
                @if ($requirement->due_at && ! ($position->deadline_at && abs($position->deadline_at->diffInMinutes($requirement->due_at)) < 1))
                    <p class="mt-2 text-sm {{ $requirement->due_at->isPast() ? 'text-urgent' : 'text-ink-muted' }}">до {{ $requirement->due_at->translatedFormat('j M, H:i') }}, <span class="nums font-medium" data-controller="timer" data-timer-until-value="{{ $requirement->due_at->toIso8601String() }}" data-timer-done-value="срок вышел"></span></p>
                @endif
            @elseif ($noInvoice)
                <p class="mt-3 font-medium">Готовим счёт</p>
            @endif

            @if ($contractStep ?? false)
                {{-- Шаг договора — чек-лист в том порядке, в каком всё делается (05.10.2026, владелец: «просит приложить
                     договор, хотя договора у него нет, он ниже формируется»): связаться с владельцем → покупатель → ДКП
                     (открыть, распечатать) → подписанный договор и кнопка шага. Сделанное — галочкой. --}}
                @php
                    $contract = \App\Offers\DealContract::for($deal)->loadMissing(['seller', 'buyer.party']);
                    $contact = $showHandover || $payload->isNotEmpty() || $replies->isNotEmpty();
                    $items = array_values(array_filter([
                        $contact ? ['contact', $handover?->where === 'У владельца' || $replies->isNotEmpty() ? 'Свяжитесь с владельцем' : 'Где автомобиль', false] : null,
                        ['buyer', $deal->isPrime() ? 'Покупатель' : 'Покупатель по ДКП', (bool) $contract->buyer?->party?->readyForContract()],
                        ['doc', 'Договор купли-продажи', $contract->isReady()],
                        ['signed', 'Подписанный договор', $requirement->getMedia('files')->isNotEmpty()],
                    ]));
                @endphp
                <ol class="task-steps">
                    @foreach ($items as $k => [$key, $title, $done])
                        <li @class(['task-step', 'is-done' => $done])>
                            <span class="task-step-mark">@if ($done)<x-ui.icon name="check" class="size-3.5"/>@else{{ $k + 1 }}@endif</span>
                            <div class="task-step-body">
                                <h4 class="task-step-title">{{ $title }}</h4>
                                @if ($key === 'contact')
                                    @if ($showHandover)@include('cabinet.deals.handover')@endif
                                    @include('cabinet.deals.payload')
                                    @if ($replies->isNotEmpty())@include('cabinet.deals.replies')@endif
                                @elseif ($key === 'buyer')
                                    @include('cabinet.deals.contract', ['part' => 'buyer', 'embedded' => true])
                                @elseif ($key === 'doc')
                                    @include('cabinet.deals.contract', ['part' => 'doc', 'embedded' => true])
                                @else
                                    @include('cabinet.deals.answer')
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ol>
            @else
            @if ($showHandover)@include('cabinet.deals.handover')@endif
            @include('cabinet.deals.payload')
            @if ($replies->isNotEmpty())@include('cabinet.deals.replies')@endif

            @if ($stepInvoices->isNotEmpty())
                <div class="list mt-5">
                    @foreach ($stepInvoices as $i)@include('cabinet.deals.invoice-row', ['invoice' => $i])<x-billing.pay-status :invoice="$i"/>@endforeach
                </div>
            @elseif ($payStep && $unpaid->isNotEmpty())
                {{-- Ссылка на оплату — тут, с «Отправить»; ждёт подтверждения — словом. --}}
                @php $links = $unpaid->filter(fn ($i) => $i->openLink() || \App\Billing\Acquiring\PayLink::eligible($i)); @endphp
                @if ($links->isNotEmpty())<div class="list mt-5">@foreach ($links as $i)<x-billing.pay-status :invoice="$i"/>@endforeach</div>@endif
                @if ($claimable->isEmpty())<p class="mt-4 font-medium">Оплата ждёт подтверждения</p>@endif
            @endif

            {{-- Действия — после всего, что нужно прочитать. --}}
            @if ($needsBuyer)
                <div class="mt-4">@include('cabinet.deals.contract', ['part' => 'buyer', 'embedded' => true])</div>
            @endif
            @if ($requirement && ! $needsBuyer)
                @if ($payStep && $unpaid->isNotEmpty())
                    @if ($claimable->isNotEmpty())
                        {{-- Та же шторка, что в «Деньгах»: ссылкой (кто платит), по счёту с платёжкой, наличными. Ответ — сюда же. --}}
                        <div data-controller="sheet" class="mt-5">
                            <x-ui.button type="button" block data-action="sheet#open">Оплатить{!! $claimable->count() === 1 ? ' <span class="nums">'.\App\Support\Money::rub($claimable->first()->remaining() - $claimable->first()->claimed()).'</span>' : '' !!}</x-ui.button>
                            <x-billing.pay-sheet :invoices="$claimable" :action="'/account/money/deals/'.$deal->id.'/pay'" pdf="/account/invoices/{id}/pdf"
                                :buyers="auth()->user()->buyers()->with(\App\Users\User::withAvatar())->orderBy('name')->get()" :open="$errors->any() && old('way')"/>
                        </div>
                    @endif
                @else
                    @include('cabinet.deals.answer')
                @endif
            @endif
            @endif
            @if ($handover?->servicePick && $showHandover)
                <form method="post" action="/deals/{{ $deal->id }}/picked" class="mt-5" data-turbo-confirm="Забрали автомобиль?" data-turbo-confirm-label="Забрал">
                    @csrf<x-ui.button block>Автомобиль забрал</x-ui.button>
                </form>
            @endif
        </div>
    @elseif ($deal->state === DealState::Active)
        <x-ui.empty>Сделка ещё не началась</x-ui.empty>
    @endif

    {{-- Нет текущего шага (сделка закрыта или ещё не в работе) — ответы своей карточкой. --}}
    @if ($replies->isNotEmpty() && ! ($deal->state === DealState::Active && $position))
        <div class="box">@include('cabinet.deals.replies', ['bare' => true])</div>
    @endif
</div>
