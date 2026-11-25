{{-- Задача сделки — одна карточка на страницу сделки и карточку машины в гараже, пока идёт сделка со страховой (гаражная
     сделка идёт по тому же маршруту). Данные — `Cabinet\DealController::stepData`. Порядок как у задачи в приложении
     (05.10.2026, владелец): что сделать → где и у кого → ответы страховой (с кем связаться, что прислали) → действия.
     Пояснений нет (владелец: «зачем эссе в интерфейсе»): шапка «чей ход» (`x-deal.turn-head`) и то, что не про деньги; счёт,
     договор, покупатель и оплата — блоком под шагом (`cabinet.deals.receipt-card`, 18.11.2026). Путь — `cabinet.deals.path`. --}}
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
    // ПРАЙМ без покупателя — ход менеджера: счёт встанет сам, как только он укажет, кому (`Deal::invoiceGap`).
    $gap = $payGap;
    // Покупатель не указан или у физлица нет паспорта со сканом (11.10.2026) — задача одна: покупатель в шторке договора.
    $needsBuyer = in_array($gap, ['buyer', 'passport'], true);
    // Шаг «Нет реквизитов» (03.11.2026, Альфа просит реквизиты): та же шторка покупателя, кнопки нет — шаг
    // проходит сам, когда данные со сканом есть (`AdvanceOnBuyerData`). Письма поставщика менеджер не видит.
    $buyerStep = $deal->isLive() && $requirement?->asks === Asks::Buyer;
    $needsBuyer = $needsBuyer || $buyerStep;
    // Гаражная Совкомбанка: счёт и договор — когда менеджер продаст (`Deal::namesBuyer`): цену он пишет «Продаю».
    $needsPrice = $gap === 'price';
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
    // Согласие менеджера (Альфа): поставщик уже согласовал — блок «Согласование с поставщиком» и «Ваш ход»
    // сбивали с толку, будто ждём поставщика (10.10.2026, владелец). Шапка — что случилось и вопрос, рядом только часы.
    $consent = $deal->isLive() && $requirement && $position?->stage->asksConsent();
@endphp
<div class="flex flex-col gap-4">
    @if (! $deal->isLive() && ! $deal->isGarage())
        {{-- Закончилась — состояние словом с датой, без своей плашки, и на телефоне тоже (11.10.2026: там закрытая сделка
             выглядела идущей). Гаражной его пишет своя карточка суммы. --}}
        <p><span class="font-medium {{ $deal->state === DealState::Done ? 'text-open' : 'text-danger' }}">{{ $deal->declinedBySupplier() ? 'Поставщик отказал в продаже' : $deal->state->label() }}</span>@if ($deal->closed_at) <span class="nums text-sm text-ink-muted">{{ $deal->closed_at->format('d.m.Y H:i') }}</span>@endif</p>
        {{-- Причина отказа поставщика (28.10.2026) — своими словами сотрудника, без названия вендора. --}}
        @if ($deal->declinedBySupplier() && $deal->close_note)<p class="text-ink-muted">{{ \App\Support\VendorNames::strip($deal->close_note) }}</p>@endif
    @endif

    @if ($deal->isLive() && $position)
        {{-- Просрочено — красным временем в шапке, без оранжевого «!» у заголовка (`.box-urgent`): «!» шапки — его ход. --}}
        <div id="step" class="box scroll-mt-4">
            {{-- Шапка — чей ход одной строкой (18.11.2026, владелец: «им должно быть всё равно, что мы там внутри делаем»): его ход —
                 само дело и сколько осталось, наш — «На согласовании» / «В обработке» и сколько идёт (`Offers\Turn`, `x-deal.turn-head`).
                 Описаний под ней нет: ни фраз этапа, ни «ждём поставщика», ни отдельного срока — только то, чем делается его шаг. --}}
            @php
                // Поставщик готовит договор — когда покупатель сможет подписать, день и время заранее (`Signing`, 11.11.2026).
                $signingDays = ! $requirement && $deal->signing_at === null && \App\Offers\Signing::asksTime($position->stage->loadMissing('exits.to.exits'));
                $turn = \App\Offers\Turn::of($deal, $position);
            @endphp
            @if ($turn)<x-deal.turn-head :turn="$turn"/>@endif
            {{-- Город подписания не указали при оплате (оплатил ссылкой, деньги пришли сами) — тот же вопрос в шаге подписания: не Москва —
                 запись пропускается (25.11.2026, `SetSigningCity`). --}}
            @if (! $position->stage->isPayStep() && $deal->needsSigningCity($position->stage))
                <x-deal.signing-city :deal="$deal" :action="'/deals/'.$deal->id.'/signing-city'" :search="'/deals/'.$deal->id.'/signing-city/places'" class="mt-4"/>
            @endif
            @if ($signingDays)
                <x-deal.signing-when :deal="$deal" :action="'/deals/'.$deal->id.'/signing-wish'" class="mt-4"/>
            @endif
            {{-- Вернули на оплату («Оплата не поступила») — факт: какая оплата и почему, пока не сообщил об оплате заново. --}}
            @if ($requirement && $rejected && ! $needsBuyer && ! $needsPrice && ! $paidUp && ! $consent)
                <p class="mt-2 font-medium text-urgent">Оплата <span class="nums">{{ \App\Support\Money::rub($rejected->amount) }}</span> от <span class="nums">{{ \App\Support\Ago::html($rejected->paid_at, words: false) }}</span> не поступила{{ $rejected->reject_reason ? ': '.$rejected->reject_reason : '' }}</p>
            @endif

            {{-- Под шапкой — только то, что не про деньги и документы (18.11.2026, владелец: «везде один блок с суммами, счётом,
                 договором, покупателем», «убирай этот путь с подписанием договора»): получение (где ТС, контакт), запись на
                 подписание, показанное из писем, кнопки ответа шага. Счёт, договор, покупатель, платёжки и подписанный договор —
                 в блоке денег под шагом (`cabinet.deals.receipt-card`), там же главная кнопка его дела по ним. --}}
            @php
                $paperStep = $requirement && ($payStep || $requirement->asks === Asks::Buyer || $needsBuyer || $needsPrice
                    || ($requirement->asks === Asks::Document && $requirement->stage?->aboutContract()) || $direct);
                $signingPick = $requirement && \App\Offers\Signing::picks($position->stage);
            @endphp
            @if ($signingPick && $deal->signing_slots)
                <x-deal.signing-pick :deal="$deal" :action="'/deals/'.$deal->id.'/signing'" :other="'/deals/'.$deal->id.'/signing-wish'" class="mt-4"/>
            @elseif ($signingPick)
                <x-deal.signing-when :deal="$deal" :action="'/deals/'.$deal->id.'/signing-wish'" picks class="mt-4"/>
            @elseif (! $deal->signing_at && \App\Offers\Signing::awaitsSlots($position->stage))
                <x-deal.signing-when :deal="$deal" readonly class="mt-4"/>
            @elseif (\App\Offers\Signing::booked($deal))
                <x-deal.appointment :deal="$deal" :confirmed="$position->waitsFor() === \App\Workflow\WaitsFor::Manager" :ics="'/deals/'.$deal->id.'/signing.ics'" class="mt-4"/>
            @endif
            @if ($showHandover)@include($handover->park ? 'cabinet.deals.park-pickup' : 'cabinet.deals.handover')@endif
            @include('cabinet.deals.payload')
            @if ($disclosures->isNotEmpty())@include('cabinet.deals.disclosures', ['skipFiles' => $direct ? \App\Offers\SupplierInvoice::shown($deal->offer)->pluck('id')->all() : []])@endif
            @if ($requirement && ! $paperStep && ! $paidUp && ! $signingPick)
                @include('cabinet.deals.answer')
            @endif
            @unless ($carCard ?? false)@include('cabinet.deals.pickup-request')@endunless
        </div>
    @elseif ($deal->isLive())
        <x-ui.empty>Сделка ещё не началась</x-ui.empty>
    @endif

    {{-- Нет текущего шага (сделка закрыта или ещё не в работе) — показанное своей карточкой. --}}
    @if ($disclosures->isNotEmpty() && ! ($deal->isLive() && $position))
        <div class="box">@include('cabinet.deals.disclosures', ['bare' => true])</div>
    @endif
</div>
