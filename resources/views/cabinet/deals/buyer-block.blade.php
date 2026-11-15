{{-- Покупатель на странице сделки (10.11.2026, владелец: «реквизиты требовать сразу, как взял в работу»): с согласия — у ДКП,
     ПРАЙМ и «страховой напрямую» (`Deal::needsBuyer`). Попросили (`buyer_asked_at`) — заголовком дело, без срока; покупателя ещё
     нет — сначала плитки «Физлицо» / «ИП» / «Организация» (поля заполняет менеджер, 13.11.2026), ниже выбор из своих
     (`cabinet.deals.contract`). Окно «Кто покупатель?» сразу после «Беру в работу» — `x-deal.buyer-ask-sheet`. Белой карточкой (11.11.2026, владелец:
     «висит в воздухе — тут как раз нужен блок»): плитки и выбор внутри неё серыми. --}}
{{-- С 16.11.2026 — карточка «Договор и счёт» под шагом (вариант А; владелец: «договор и счёт сформируем мы, если вы введёте
     данные покупателя, а если уже подписали — можно приложить; разделить эти понятия»): покупатель и кто платит, наш договор и
     счёт — **файлами** PDF с «Скачать» («чтобы поняли, что это файл»), пока не готовы — фразой, что подготовим сами. Подписанный
     без нас — своей карточкой «Договор уже подписан?» ниже (`x-deal.signed-contract ask`). Заголовок — по схеме сделки. --}}
@php
    use App\Offers\DealScheme;
    $asked = $deal->buyer_asked_at;
    $contract = \App\Offers\DealContract::for($deal);
    $chosen = (bool) $contract->buyer_user_id;
    $withBuyer ??= true;
    $scheme = $deal->schemeOf();
    $ours = in_array($scheme, [DealScheme::Prime, DealScheme::Ros], true);
    // Счёт за ТС от ПРАЙМ / Р.О.С. — файлом, когда выставлен.
    $sale = $ours ? $deal->invoices()->where('kind', \App\Billing\ChargeKind::Sale)->where('state', '!=', \App\Billing\InvoiceState::Void->value)->with('media')->latest('id')->first() : null;
    $docsReady = $deal->hasContract() ? ($contract->missing() === [] && (! $ours || $sale)) : true;
    $title = match (true) {
        $withBuyer && $asked && ! $chosen => 'Укажите покупателя',
        $deal->isGarage() => 'Договор купли-продажи',
        $ours => 'Договор и счёт',
        $deal->hasContract() => 'Договор',
        default => 'Покупатель',
    };
    $hint = $docsReady ? null : match (true) {
        $ours => 'Подготовим сами по данным покупателя',
        $deal->hasContract() => 'Подготовим договор по данным покупателя',
        default => null,
    };
    if (! $deal->hasContract() && $deal->collectsBuyer()) {
        $hint = 'Поставщик выставит счёт на покупателя';
    }
    // Счёт файлом — строкой той же группы, после договора.
    $saleRow = match (true) {
        (bool) $sale => \Illuminate\Support\Facades\Blade::render('<x-ui.file :name="$name" mime="application/pdf" :href="$href" :bytes="$bytes" download class="row"/>',
            ['name' => 'Счёт '.(str_starts_with($sale->label(), '№') ? $sale->label() : '№ '.$sale->label()), 'href' => '/account/invoices/'.$sale->id.'/pdf', 'bytes' => $sale->getFirstMedia('file')?->size]),
        $ours && $chosen => '<div class="row sale-row"><span class="sale-row-label">Счёт</span><span class="sale-row-value text-ink-muted">'
            .e($contract->buyer?->party?->readyAsBuyer() ? 'готовим' : 'ждём данные').'</span></div>',
        default => '',
    };
@endphp
<section class="box deal-form min-w-0">
    <h2 class="box-title">{{ $title }}</h2>
    @if ($hint)<p class="mt-1">{{ $hint }}</p>@endif
    @if ($withBuyer)
        @if (! $chosen && $deal->isLive())<div class="mt-3"><x-deal.buyer-kind :deal="$deal"/></div>@endif
        <div class="mt-2">@include('cabinet.deals.contract', ['part' => $deal->hasContract() ? 'all' : 'buyer', 'embedded' => true, 'after' => $saleRow, 'labels' => true])</div>
    @elseif ($saleRow !== '')
        <div class="list mt-2">{!! $saleRow !!}</div>
    @endif
</section>
