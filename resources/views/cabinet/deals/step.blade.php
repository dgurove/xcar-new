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
    // За ТС платят не нам, а поставщику или собственнику (Альфа, «Страховой напрямую»; 18.10.2026): задача — заплатить им и
    // приложить платёжку, её и подтверждает поставщик. Наш подбор — отдельной строкой ниже, шаг он не держит.
    $direct = $payStep && $deal->paysSupplierOn($position->stage);
    $unpaid = $invoices->filter(fn ($i) => ! $i->isOwed() && $i->state === \App\Billing\InvoiceState::Issued);
    // Шаг оплаты, а счета уже оплачены (оплатили по ссылке, маршрут ещё не догнал) — «Оплатите» с «Приложить» тут врут.
    $paidUp = $payStep && ! $direct && $unpaid->isEmpty() && $deal->fullyPaid();
    // Оплата, а счёта ещё нет: ход наш (`Position::awaitsInvoice`), просить оплатить нечего — счёт готовим.
    $noInvoice = (bool) $position?->awaitsInvoice();
    // ПРАЙМ без покупателя — ход менеджера: счёт встанет сам, как только он укажет, кому (`Deal::invoiceGap`).
    $gap = $deal->isLive() && $position?->stage->isPayStep() && $deal->hasContract() ? $deal->invoiceGap() : null;
    // Покупатель не указан или у физлица нет паспорта со сканом (11.10.2026) — задача одна: покупатель в шторке договора.
    $needsBuyer = in_array($gap, ['buyer', 'passport'], true);
    // Шаг «Нет реквизитов» (03.11.2026, Альфа просит реквизиты): та же шторка покупателя, кнопки нет — шаг
    // проходит сам, когда данные со сканом есть (`AdvanceOnBuyerData`). Письма поставщика менеджер не видит.
    $buyerStep = $deal->isLive() && $requirement?->asks === Asks::Buyer;
    $needsBuyer = $needsBuyer || $buyerStep;
    // Реквизиты прислал фотографией — вносим мы (10.11.2026, `deals.buyer_scans_at`): ни выбора, ни часов, одно слово.
    $photosSent = $needsBuyer && $deal->buyer_scans_at;
    $needsBuyer = $needsBuyer && ! $photosSent;
    // Покупатель ещё не выбран — сначала плитки «Паспорт» / «Карточка фирмы» (`x-deal.buyer-photos`).
    $photoTiles = $needsBuyer && ! \App\Offers\DealContract::for($deal)->buyer_user_id;
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
    // Машина на нашей парковке — блок «Забрать с парковки», как только сделке можно забирать (и гаражной: у неё `open` нет).
    // Своей карточкой «Автомобиль» под шагом (`car-card`) — тогда здесь получения нет.
    $showHandover = ! ($carCard ?? false) && ($handover?->shows() || ($handover?->park && $deal->releasable())) && ! $handover->withBuyer;
    // Поля шага, что показаны в получении (контакт, адрес, дата), второй раз в шаге не пишем.
    // Наши служебные поля про деньги поставщику (дата и номер платёжки, перевод с Р.О.С., сумма сделки страховой) —
    // не менеджеру; в остальных — без названия вендора (15.10.2026).
    $labels = collect($position?->stage->staff_fields ?? [])->pluck('label', 'key');
    $payload = collect($position?->fields() ?? [])->except($handover?->open ? $handover->keys : [])
        ->reject(fn ($v, $k) => preg_match('/платёж|оплат|сумм|перевод|цен/iu', (string) ($labels[$k] ?? $k)))
        ->map(fn ($v) => is_string($v) ? \App\Support\VendorNames::strip($v) : $v)->filter(fn ($v) => filled($v));
    // Показанное админом из писем страховой (`Offers\Disclosure`) — по порядку. Много — ранние под «Ещё».
    $disclosures = ($disclosures ?? collect())->reverse()->values();
    $early = $disclosures->count() > 3 ? $disclosures->slice(0, $disclosures->count() - 2) : collect();
    $late = $disclosures->slice($early->count());
    $ownerPhone = $disclosures->contains(fn ($d) => $d->values('phone') !== []);
    // Согласие менеджера (Альфа): поставщик уже согласовал — блок «Согласование с поставщиком» и «Ваш ход»
    // сбивали с толку, будто ждём поставщика (10.10.2026, владелец). Шапка — что случилось и вопрос, рядом только часы.
    $consent = $deal->isLive() && $requirement && $position?->stage->asksConsent();
    // До согласия ждём поставщика (27.10.2026, владелец: менеджер не знает, что будет дальше): одной строкой — что дальше
    // спросим его. О письмах поставщика ни слова.
    $consentAhead = $deal->isLive() && ! $consent && ! $deal->agreed() && $position?->stage->loadMissing('exits.to.exits')->exits->contains(fn ($e) => $e->to?->asksConsent());
@endphp
<div class="flex flex-col gap-6">
    @if (! $deal->isLive() && ! $deal->isGarage())
        {{-- Закончилась — состояние словом с датой, без своей плашки, и на телефоне тоже (11.10.2026: там закрытая сделка
             выглядела идущей). Гаражной его пишет своя карточка суммы. --}}
        <p><span class="font-medium {{ $deal->state === DealState::Done ? 'text-open' : 'text-danger' }}">{{ $deal->declinedBySupplier() ? 'Поставщик отказал в продаже' : $deal->state->label() }}</span>@if ($deal->closed_at) <span class="nums text-sm text-ink-muted">{{ $deal->closed_at->format('d.m.Y H:i') }}</span>@endif</p>
        {{-- Причина отказа поставщика (28.10.2026) — своими словами сотрудника, без названия вендора. --}}
        @if ($deal->declinedBySupplier() && $deal->close_note)<p class="text-ink-muted">{{ \App\Support\VendorNames::strip($deal->close_note) }}</p>@endif
    @endif

    @if ($deal->isLive() && $position)
        <div id="step" class="box scroll-mt-4 {{ $requirement && $position->isOverdue() && ! $paidUp ? 'box-urgent' : '' }}">
            {{-- Шапка — одна фраза, что сделать (10.11.2026, владелец: «зачем писать „Оплата / Оплатить“, „Получение автомобиля /
                 Заберите автомобиль“ — пользователь не тупой»): заголовок — само дело, под ним срок; имя этапа — только когда
                 делать менеджеру нечего (ждём, готовим счёт) или дело говорит чек-лист ниже. --}}
            @php
                // Только «Ждём поставщика»: у наших этапов фраза — инструкция менеджеру («Свяжитесь с владельцем…»), не статус.
                // «Запись на подписание» — и пока письмо не ушло (ждём XCar): «Записываем на подписание, с 11.11» (11.11.2026).
                $statusLine = ! $requirement && $position->stage->ask_text
                    && ($position->waitsFor() === \App\Workflow\WaitsFor::Supplier || \App\Offers\Signing::awaitsSlots($position->stage))
                    ? \App\Users\Wording::mine($position->stage->ask_text).($position->entered_at ? ', с '.\App\Support\Ago::day($position->entered_at) : '') : null;
                // Поставщик готовит договор — когда покупатель сможет подписать, день и время заранее (`Signing`, 11.11.2026).
                $signingDays = ! $requirement && $deal->signing_at === null && \App\Offers\Signing::asksTime($position->stage->loadMissing('exits.to.exits'));
                $heading = match (true) {
                    $consent => 'Берёте в работу?',
                    $signingDays && \App\Offers\Signing::wishDue($deal) => 'Когда покупатель сможет подписать договор?',
                    $photosSent => 'Реквизиты по фото у XCar',
                    $needsBuyer && ! $buyerStep => $gap === 'passport' ? 'Приложите паспорт покупателя' : 'Укажите покупателя',
                    $needsPrice => 'Укажите цену продажи',
                    $paidUp => 'Оплата получена',
                    // Просит подписанный договор, а покупателя для него нет — сначала покупатель (`Deal::contractBuyerTask`).
                    (bool) ($contractGap = $deal->contractBuyerTask($requirement)) => $contractGap['title'],
                    // Поставщик предложил своё время — выбрать из него (`Signing`, 11.11.2026).
                    $requirement && $deal->signing_slots && \App\Offers\Signing::picks($position->stage) => 'Поставщик предложил другое время',
                    // Встреча записана — дата в карточке ниже, в заголовке её не повторяем.
                    $requirement && \App\Offers\Signing::booked($deal) => \App\Users\Wording::mine($position->stage->managerTitle()),
                    $requirement && ! ($pickupSteps ?? null) => $direct
                        ? trim(preg_replace('/^Оплатить/u', 'Оплатите', (string) $deal->schemeOf()->payLabel()).' '.($deal->supplierPayment() ? \App\Support\Money::rub($deal->supplierPayment()) : ''))
                        : \App\Users\Wording::mine($requirement->title),
                    // Ждём поставщика или нас, а у этапа есть фраза для менеджера — она (11.11.2026, Альфа: «Оплата получена,
                    // поставщик готовит договор» вместо «Оформление документов»).
                    // Ждём поставщика или нас, а у этапа есть фраза: заголовком — чей ход, фраза с датой — строкой под ним
                    // (11.11.2026, владелец: «уродство» — фраза заголовком и «Ждём … с …» под ней).
                    // Менеджеру «мы» — XCar («Ждём XCar», как в «Сделках»).
                    $statusLine !== null => $position->waitsFor() === \App\Workflow\WaitsFor::Us ? 'Ждём XCar' : $position->waitsFor()->label(),
                    default => \App\Users\Wording::mine($position->stage->block?->name) ?? 'Идёт работа',
                };
            @endphp
            <h2>{{ $heading }}</h2>
            @if ($statusLine !== null)<p class="mt-1 text-sm text-ink-muted">{{ $statusLine }}</p>@endif
            @if ($signingDays)
                <x-deal.signing-when :deal="$deal" :action="'/deals/'.$deal->id.'/signing-wish'" class="mt-4"/>
            @endif
            @unless ($noInvoice || $paidUp || $photosSent || $statusLine !== null)<div class="mt-1 text-sm"><x-route.clock :position="$position" :bare="$consent || $position->waitsFor() === \App\Workflow\WaitsFor::Manager"/></div>@endunless
            @if ($photosSent)
                <p class="mt-2 text-sm text-ink-muted">Внесём сами, шаг пройдёт без вас</p>
            @elseif ($requirement && ! ($pickupSteps ?? null) && ! $needsBuyer && ! $needsPrice && ! $paidUp && ! $consent)
                @if ($rejected)<p class="mt-2 font-medium text-urgent">Оплата <span class="nums">{{ \App\Support\Money::rub($rejected->amount) }}</span> от <span class="nums">{{ \App\Support\Ago::html($rejected->paid_at, words: false) }}</span> не поступила{{ $rejected->reject_reason ? ': '.$rejected->reject_reason : '' }}</p>@endif
                {{-- Срок просьбы — тот же, что часы под заголовком: второй раз его не пишем. --}}
                @if ($requirement->due_at && ! ($position->deadline_at && abs($position->deadline_at->diffInMinutes($requirement->due_at)) < 1))
                    <p class="mt-2 text-sm {{ $requirement->due_at->isPast() ? 'text-urgent' : 'text-ink-muted' }}">до {{ \App\Support\Ago::html($requirement->due_at, true, words: false) }}, <span class="nums font-medium" data-controller="timer" data-timer-until-value="{{ $requirement->due_at->toIso8601String() }}" data-timer-done-value="срок вышел"></span></p>
                @endif
            @elseif ($noInvoice)
                <p class="mt-3 font-medium">Готовим счёт</p>
            @elseif ($consentAhead)
                <p class="mt-3 text-sm text-ink-muted">Дальше спросим, берёте ли вы в работу, — здесь и в Telegram</p>
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
                    $contact = $showHandover || $payload->isNotEmpty() || $disclosures->isNotEmpty();
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
                        $contact ? ['contact', $handover?->where === 'У владельца' || $ownerPhone ? 'Свяжитесь с владельцем' : 'Где автомобиль', 'open'] : null,
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
                                    @if ($showHandover)@include($handover->park ? 'cabinet.deals.park-pickup' : 'cabinet.deals.handover')@endif
                                    @include('cabinet.deals.payload')
                                    @if ($disclosures->isNotEmpty())@include('cabinet.deals.disclosures')@endif
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
            {{-- Платит поставщику сам — его счёт тут же, первым (10.11.2026, 0790/046/11079/26: счёт был в «Информации по сделке»
                 номером со сканера, а на шаге «Оплатить страховой» его не было). Пока не показали — словом. --}}
            @php $supplierBills = $direct ? \App\Offers\SupplierInvoice::shown($deal->offer) : collect(); @endphp
            @if ($direct && \App\Offers\SupplierInvoice::onPay($deal, $position->stage))
                @if ($supplierBills->isNotEmpty())
                    <div class="list mt-4">
                        {{-- Счёт — своим именем и с «Поделиться» (10.11.2026: менеджеры не узнавали счёт в файле со сканера и не могли
                             переслать его тому, кто платит); чек — кнопкой ниже, с камеры. --}}
                        @foreach ($supplierBills as $f)<x-ui.file name="Счёт поставщика" :mime="$f->mime_type" :size="'от '.$f->created_at->timezone('Europe/Moscow')->format('d.m')" href="/files/{{ $f->id }}" download/>@endforeach
                    </div>
                @else
                    <p class="mt-3 text-ink-muted">Счёт поставщика пришлём сюда</p>
                @endif
            @endif
            {{-- Запись на подписание (`Offers\Signing`): выбрать время плиткой, потом — карточка встречи. --}}
            @php $signingPick = $requirement && \App\Offers\Signing::picks($position->stage); @endphp
            @if ($signingPick && $deal->signing_slots)
                <x-deal.signing-pick :deal="$deal" :action="'/deals/'.$deal->id.'/signing'" :other="'/deals/'.$deal->id.'/signing-wish'" class="mt-4"/>
            @elseif ($signingPick)
                <x-deal.signing-when :deal="$deal" :action="'/deals/'.$deal->id.'/signing-wish'" picks class="mt-4"/>
            @elseif (! $deal->signing_at && \App\Offers\Signing::awaitsSlots($position->stage))
                {{-- Письмо записи: просим одно из названных — строкой, без «Изменить». --}}
                <x-deal.signing-when :deal="$deal" readonly class="mt-4"/>
            @elseif (\App\Offers\Signing::booked($deal))
                <x-deal.appointment :deal="$deal" :confirmed="$position->waitsFor() === \App\Workflow\WaitsFor::Manager" :ics="'/deals/'.$deal->id.'/signing.ics'" class="mt-4"/>
            @endif
            @if ($showHandover)@include($handover->park ? 'cabinet.deals.park-pickup' : 'cabinet.deals.handover')@endif
            @include('cabinet.deals.payload')
            @if ($disclosures->isNotEmpty())@include('cabinet.deals.disclosures', ['skipFiles' => $supplierBills->pluck('id')->all()])@endif

            @if ($stepInvoices->isNotEmpty())
                <div class="list mt-5">
                    @foreach ($stepInvoices as $i)@include('cabinet.deals.invoice-row', ['invoice' => $i])<x-billing.pay-status :invoice="$i" compact/>@endforeach
                </div>
            @elseif ($selectionPay && ! $direct)
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
                @if ($photoTiles)<div class="mt-4"><x-deal.buyer-photos :deal="$deal"/></div>@endif
                <div class="mt-4">@include('cabinet.deals.contract', ['part' => 'buyer', 'embedded' => true])</div>
            @endif
            @if ($requirement && ! $needsBuyer && ! $photosSent && (! $selectionPay || $direct) && ! $paidUp && ! $signingPick)
                @if ($payStep && $unpaid->isNotEmpty() && ! $direct)
                    @if ($claimable->isNotEmpty())
                        {{-- Та же шторка, что в «Деньгах»: ссылкой (кто платит), по счёту с платёжкой, наличными. Ответ — сюда же. --}}
                        <div data-controller="sheet" class="mt-5">
                            <x-ui.button type="button" block data-action="sheet#open">Оплатить{!! $claimable->count() === 1 ? ' <span class="nums">'.\App\Support\Money::rub($claimable->first()->remaining() - $claimable->first()->claimed()).'</span>' : '' !!}</x-ui.button>
                            <x-billing.pay-sheet :invoices="$claimable" :action="'/account/money/deals/'.$deal->id.'/pay'" pdf="/account/invoices/{id}/pdf"
                                :buyers="auth()->user()->buyers()->with(\App\Users\User::withAvatar())->orderBy('name')->get()" :open="($errors->any() && old('way')) || request()->boolean('pay')"/>
                        </div>
                    @endif
                @else
                    @include('cabinet.deals.answer', $direct ? ['attachLabel' => 'Приложить чек или платёжку'] : [])
                @endif
            @endif
            @if ($direct && $selectionPay)
                {{-- Наш подбор — своей группой под платежом поставщику, вторичной кнопкой. --}}
                <div class="mt-6 border-t border-line pt-5">
                    <h4 class="font-medium">Оплата услуг подбора</h4>
                    @include('cabinet.deals.selection-pay', ['invoice' => $selectionPay, 'variant' => 'secondary', 'class' => 'mt-3'])
                </div>
            @endif
            @endif
            {{-- В чек-листе договора с получением строка стоит под «Заберите автомобиль». --}}
            @unless ((($contractStep ?? false) && ($pickupSteps ?? null)) || ($carCard ?? false))@include('cabinet.deals.pickup-request')@endunless
        </div>
    @elseif ($deal->isLive())
        <x-ui.empty>Сделка ещё не началась</x-ui.empty>
    @endif

    {{-- Нет текущего шага (сделка закрыта или ещё не в работе) — показанное своей карточкой. --}}
    @if ($disclosures->isNotEmpty() && ! ($deal->isLive() && $position))
        <div class="box">@include('cabinet.deals.disclosures', ['bare' => true])</div>
    @endif
</div>
