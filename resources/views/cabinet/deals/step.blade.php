{{-- Задача сделки — одна карточка на страницу сделки и карточку машины в гараже, пока идёт сделка со страховой (гаражная
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
    // Шаг оплаты, а счета уже оплачены (оплатили по ссылке, маршрут ещё не догнал) — «Оплатите» с «Приложить» тут врут.
    $paidUp = $payStep && $unpaid->isEmpty() && $deal->fullyPaid();
    // Оплата, а счёта ещё нет: ход наш (`Position::awaitsInvoice`), просить оплатить нечего — счёт готовим.
    $noInvoice = (bool) $position?->awaitsInvoice();
    // ПРАЙМ без покупателя — ход менеджера: счёт встанет сам, как только он укажет, кому (`Deal::invoiceGap`).
    $gap = $deal->isActive() && $position?->stage->isPayStep() && $deal->hasContract() ? $deal->invoiceGap() : null;
    // Покупатель не указан или у физлица нет паспорта со сканом (11.10.2026) — задача одна: покупатель в шторке договора.
    $needsBuyer = in_array($gap, ['buyer', 'passport'], true);
    // Гаражная Совкомбанка: счёт и договор — когда менеджер продаст (`Deal::namesBuyer`): цену он пишет «Продаю».
    $needsPrice = $gap === 'price';
    // Строки счетов — только в гараже, где «Расчёта» нет; на странице сделки счёт в «Расчёте», а здесь — сама оплата.
    // На шаге оплаты — только сама оплата (ссылка и «Оплатить N ₽»), строка счёта повторяла бы ту же сумму.
    $stepInvoices = ($withInvoices ?? false) && ! $payStep ? $unpaid : collect();
    // Подбор (ДКП, «страховой напрямую») на шаге оплаты — группой «Оплатите XCar за подбор», как в «Расчёте».
    $selectionPay = $payStep && ! ($withInvoices ?? false) && $deal->paysSelection()
        ? $unpaid->first(fn ($i) => $i->kind === \App\Billing\ChargeKind::Selection) : null;
    $claimable = $payStep ? $unpaid->filter(fn ($i) => $i->kind !== \App\Billing\ChargeKind::Reward && $i->remaining() - $i->claimed() > 0)->values() : collect();
    // Вернули на оплату («Оплата не поступила») — менеджер видит почему, пока не сообщил об оплате заново.
    $rejected = $payStep && $unpaid->every(fn ($i) => $i->claimed() == 0)
        ? \App\Billing\Payment::whereIn('invoice_id', $unpaid->pluck('id'))->where('state', \App\Billing\PaymentState::Rejected)->latest('id')->first() : null;
    $handover ??= null;
    // Машина уже у менеджера — «У вас» одним словом над чужой задачей (оплата, договор) ни о чём: получения больше нет.
    $showHandover = $handover?->shows() && ! $handover->withBuyer;
    // Поля шага, что показаны в получении (контакт, адрес, дата), второй раз в шаге не пишем.
    // Наши служебные поля про деньги поставщику (дата и номер платёжки, перевод с Р.О.С., сумма сделки страховой) —
    // не менеджеру; в остальных — без названия вендора (15.10.2026).
    $labels = collect($position?->stage->staff_fields ?? [])->pluck('label', 'key');
    $payload = collect($position?->fields() ?? [])->except($handover?->open ? $handover->keys : [])
        ->reject(fn ($v, $k) => preg_match('/платёж|оплат|сумм|перевод|цен/iu', (string) ($labels[$k] ?? $k)))
        ->map(fn ($v) => is_string($v) ? \App\Support\VendorNames::strip($v) : $v)->filter(fn ($v) => filled($v));
    // Ответы — перепиской, по порядку писем: сначала контакт, потом документы. Много — ранние под «Ещё».
    $replies = ($replies ?? collect())->reverse()->values();
    $early = $replies->count() > 3 ? $replies->slice(0, $replies->count() - 2) : collect();
    $late = $replies->slice($early->count());
    // Согласие менеджера (Альфа): поставщик уже согласовал — блок «Согласование с поставщиком» и «Ваш ход»
    // сбивали с толку, будто ждём поставщика (10.10.2026, владелец). Шапка — что случилось и вопрос, рядом только часы.
    $consent = $deal->isActive() && $requirement && $position?->stage->asksConsent();
@endphp
<div class="flex flex-col gap-6">
    @if ($deal->state !== DealState::Active && ! $deal->isGarage())
        {{-- Закончилась — состояние словом с датой, без своей плашки, и на телефоне тоже (11.10.2026: там закрытая сделка
             выглядела идущей). Гаражной его пишет своя карточка суммы. --}}
        <p><span class="font-medium {{ $deal->state === DealState::Done ? 'text-open' : 'text-danger' }}">{{ $deal->state->label() }}</span>@if ($deal->closed_at) <span class="nums text-sm text-ink-muted">{{ $deal->closed_at->translatedFormat('j M Y, H:i') }}</span>@endif</p>
    @endif

    @if ($deal->state === DealState::Active && $position)
        <div class="box {{ $requirement && $position->isOverdue() && ! $paidUp ? 'box-urgent' : '' }}">
            @if ($consent)
                <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                    <h2>Поставщик согласовал</h2>
                    <x-route.clock :position="$position" bare/>
                </div>
                <h3 class="mt-5 text-lg">Подтверждаете, что берёте в работу?</h3>
            @else
            <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                <h2>{{ \App\Users\Wording::mine($position->stage->block?->name) ?? 'Идёт работа' }}</h2>
                {{-- Счёта нет — ниже «Готовим счёт», второй раз в шапке не пишем. Ход менеджера — только часы: что сделать,
                     говорит заголовок задачи ниже, «Ваш ход» над ним — лишнее слово (без срока — ничего). --}}
                @unless ($noInvoice || $paidUp)<x-route.clock :position="$position" :bare="$position->waitsFor() === \App\Workflow\WaitsFor::Manager"/>@endunless
            </div>
            @endif

            @if ($consent)
            @elseif ($needsBuyer)
                <h3 class="mt-5 text-lg">{{ $gap === 'passport' ? 'Приложите паспорт покупателя' : 'Укажите покупателя' }}</h3>
            @elseif ($needsPrice)
                <h3 class="mt-5 text-lg">Укажите цену продажи</h3>
            @elseif ($paidUp)
                <h3 class="mt-5 text-lg">Оплата получена</h3>
            @elseif ($requirement && ! ($pickupSteps ?? null))
                {{-- Чек-лист получения сам говорит, что делать сейчас: заголовок просьбы повторял бы его пункт. --}}
                <h3 class="mt-5 text-lg">{{ \App\Users\Wording::mine($requirement->title) }}</h3>
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
                     (открыть, распечатать) → подписанный договор и кнопка шага. Сделанное — галочкой. У ДКП Т-Страхования
                     (06.10.2026, владелец: «сначала отмечает, что связался, потом — что забрал») ещё «Заберите
                     автомобиль», и «Связался» / «Забрал» — шаги маршрута: кнопка стоит у текущего пункта, будущие
                     приглушены. Покупателя и ДКП готовят когда угодно — их подписывают при передаче. --}}
                @php
                    $contract = \App\Offers\DealContract::for($deal)->loadMissing(['seller', 'buyer.party']);
                    $contact = $showHandover || $payload->isNotEmpty() || $replies->isNotEmpty();
                    $flow = $pickupSteps ?? null;
                    $signedFiles = $requirement->asks === Asks::Document && $requirement->getMedia('files')->isNotEmpty();
                    $state = fn (bool $done) => $done ? 'done' : 'open';
                    $items = array_values(array_filter($flow ? [
                        ['contact', 'Свяжитесь с владельцем', $flow['contact']],
                        ['pickup', 'Заберите автомобиль', $flow['pickup']],
                        ['buyer', 'Покупатель по ДКП', $state((bool) $contract->buyer?->party?->readyAsBuyer())],
                        ['doc', 'Договор купли-продажи', $state($contract->isReady())],
                        ['signed', 'Подписанный договор', $flow['signed'] === 'current' && $signedFiles ? 'done' : $flow['signed']],
                    ] : [
                        $contact ? ['contact', $handover?->where === 'У владельца' || $replies->isNotEmpty() ? 'Свяжитесь с владельцем' : 'Где автомобиль', 'open'] : null,
                        ['buyer', $deal->isPrime() ? 'Покупатель' : 'Покупатель по ДКП', $state((bool) $contract->buyer?->party?->readyAsBuyer())],
                        ['doc', 'Договор купли-продажи', $state($contract->isReady())],
                        ['signed', 'Подписанный договор', $state($signedFiles)],
                    ]));
                    // Кнопка шага — у пункта, что сейчас: «Связался», «Забрал» или «Договор приложен».
                    $here = $flow ? ($flow['contact'] === 'current' ? 'contact' : ($flow['pickup'] === 'current' ? 'pickup' : 'signed')) : 'signed';
                @endphp
                <ol class="task-steps">
                    @foreach ($items as $k => [$key, $title, $mark])
                        <li @class(['task-step', 'is-done' => $mark === 'done', 'is-later' => $mark === 'later'])>
                            @if ($key === 'signed')
                                @include('cabinet.deals.signed-mark', ['done' => $mark === 'done', 'n' => $k + 1])
                            @else
                                <span class="task-step-mark">@if ($mark === 'done')<x-ui.icon name="check" class="size-3.5"/>@else{{ $k + 1 }}@endif</span>
                            @endif
                            <div class="task-step-body">
                                <h4 class="task-step-title">{{ \App\Users\Wording::mine($title) }}</h4>
                                @if ($key === 'contact')
                                    @if ($showHandover)@include('cabinet.deals.handover')@endif
                                    @include('cabinet.deals.payload')
                                    @if ($replies->isNotEmpty())@include('cabinet.deals.replies')@endif
                                @elseif ($key === 'buyer')
                                    @include('cabinet.deals.contract', ['part' => 'buyer', 'embedded' => true])
                                @elseif ($key === 'doc')
                                    @include('cabinet.deals.contract', ['part' => 'doc', 'embedded' => true])
                                @endif
                                @if ($key === $here)@include('cabinet.deals.answer')@endif
                                @if ($key === 'pickup')@include('cabinet.deals.pickup-request', ['inline' => true])@endif
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
                    @foreach ($stepInvoices as $i)@include('cabinet.deals.invoice-row', ['invoice' => $i])<x-billing.pay-status :invoice="$i" compact/>@endforeach
                </div>
            @elseif ($selectionPay)
                {{-- Подбор по ДКП и «страховой напрямую» — та же группа, что в «Расчёте» (там её на этом шаге нет). --}}
                @include('cabinet.deals.selection-pay', ['invoice' => $selectionPay])
            @elseif ($payStep && $unpaid->isNotEmpty())
                {{-- Ссылка на оплату — тут, с «Отправить»; ждёт подтверждения — словом. --}}
                @php $links = $unpaid->filter(fn ($i) => $i->openLink() || \App\Billing\Acquiring\PayLink::eligible($i)); @endphp
                @if ($links->isNotEmpty())<div class="list mt-5">@foreach ($links as $i)<x-billing.pay-status :invoice="$i" compact/>@endforeach</div>@endif
                @if ($claimable->isEmpty())<p class="mt-4 font-medium">Оплата ждёт подтверждения</p>@endif
            @endif

            {{-- Действия — после всего, что нужно прочитать. --}}
            @if ($needsBuyer)
                <div class="mt-4">@include('cabinet.deals.contract', ['part' => 'buyer', 'embedded' => true])</div>
            @endif
            @if ($requirement && ! $needsBuyer && ! $selectionPay && ! $paidUp)
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
            {{-- В чек-листе договора с получением строка стоит под «Заберите автомобиль». --}}
            @unless (($contractStep ?? false) && ($pickupSteps ?? null))@include('cabinet.deals.pickup-request')@endunless
        </div>
    @elseif ($deal->state === DealState::Active)
        <x-ui.empty>Сделка ещё не началась</x-ui.empty>
    @endif

    {{-- Нет текущего шага (сделка закрыта или ещё не в работе) — ответы своей карточкой. --}}
    @if ($replies->isNotEmpty() && ! ($deal->state === DealState::Active && $position))
        <div class="box">@include('cabinet.deals.replies', ['bare' => true])</div>
    @endif
</div>
