{{-- Покупатель сделки в CRM — выбирает менеджер, админ может за него (20.10.2026, владелец: «пока менеджер не укажет покупателя, мы
     не можем закрыть сделку»; «такой же удобный выбор и создание покупателя»): та же шторка `x-people.pick`, что у менеджера, —
     сам менеджер, его покупатели, «+ Новый покупатель» (встаёт в «Покупатели» менеджера и в сделку); нет данных — их шторка со
     сканом паспорта; у ПРАЙМ — кто платит по счёту. Строками внутри `.list`: в ДКП (`x-deal.contract`) и в «Покупателе для
     счёта» поставщика (`x-deal.invoice-buyer`, 03.11.2026). --}}
@props(['deal', 'contract' => null, 'autoOpen' => true])
@php
    $contract ??= \App\Offers\DealContract::for($deal)->loadMissing(['buyer.party']);
    $manager = $deal->buyer;
    $buyer = $contract->buyer;
    // Параллельная (07.11.2026) — тоже живая: её покупателя смотрят и заполняют за менеджера так же.
    $live = $deal->isLive() && ! $deal->isDirect() && $manager?->isManager();
    $isManager = $buyer && $buyer->id === $deal->buyer_id;
    $ready = $buyer?->party?->readyAsBuyer();
    $gap = $buyer?->party?->buyerGap();
    // Строка — как в «Покупателях» (06.11.2026, владелец: «Покупатель  Гамидов Та…» — подпись слева съедала ФИО): аватар, имя во
    // всю строку, под ним состояние; у ИП и организации — вид словом.
    $who = $buyer ? ($buyer->party?->name ?: $buyer->name) : null;
    $kindWord = match ($buyer?->party?->kind) { \App\Billing\PartyKind::Entrepreneur => 'ИП, ', \App\Billing\PartyKind::Company => 'организация, ', default => '' };
    $dataSheet = 'deal-buyer-data-'.$deal->id;
    // «Заполнить» / «Заполнить за менеджера» из шага пути и «Ваш ход» (`?buyer=1`, 06.11.2026: ссылка на `#invoice-buyer` только
    // прокручивала к карточке — шторку надо было ещё найти): данных нет — сразу их шторка, покупателя нет — его выбор.
    // Покупатели параллельных на той же странице сами не открываются (`autoOpen` = false): `?buyer=1` — про главную сделку.
    $ask = $autoOpen && $live && request()->boolean('buyer') && ! $ready;
    // Без договора (поставщик выставит счёт на покупателя, 03.11.2026) — «данные для счёта».
    $for = $deal->hasContract() ? 'договора' : 'счёта';
    // Скан паспорта одним PDF — посмотреть и скачать (05.11.2026, владелец: «когда менеджер заполняет данные покупателя, их
    // невозможно посмотреть и скачать в админке»).
    $scanPdf = $buyer?->party?->needsPassportScan() && $buyer->party->getMedia('passport')->isNotEmpty()
        ? ['url' => '/parties/'.$buyer->party->id.'/passport.pdf', 'type' => 'pdf', 'name' => \App\Billing\Documents\PassportPdf::fileName($buyer->party), 'label' => 'Паспорт'] : null;
    // Реквизиты фотографией (`x-deal.buyer-photos`, «Карточка фирмы»): снимки, что прислал менеджер, — здесь же, чтобы внести
    // данные с них (13.11.2026, Тужиков прислал реквизиты фото, а в CRM их не было видно нигде: «не вижу ни в чате, ни в сделке»).
    $cards = $buyer?->party ? $buyer->party->getMedia('card') : collect();
@endphp
{{-- Данные есть — та же шторка: посмотреть, поправить, скан PDF (05.11.2026). --}}
@if ($buyer && $live)
    <div data-controller="sheet" class="contents">
        <x-ui.sheet-row>
            <x-ui.avatar :user="$buyer" :size="36"/>
            <span class="min-w-0 flex-1"><span class="block truncate">{{ $who }}{{ $isManager ? ', менеджер' : '' }}</span>@if ($ready)<span class="row-sub text-open">{{ $kindWord }}данные есть</span>@else<span class="row-sub text-urgent">{{ $gap ?? 'нужны данные для '.$for }}</span>@endif</span>
            <x-ui.chevron/>
        </x-ui.sheet-row>
        <x-ui.sheet :id="$dataSheet" :title="$ready ? 'Данные покупателя' : 'Данные для '.$for" :open="session('open_sheet') === $dataSheet || $errors->hasAny(['buyer.*']) || $ask" tall>
            @php $buyerParty = $buyer->party ?? \App\Billing\Party::forUser($buyer); @endphp
            {{-- Снимки реквизитов от менеджера — над полями: вносят, глядя на них. --}}
            @if ($cards->isNotEmpty())
                <div class="list mb-3">
                    @foreach ($cards as $n => $card)<x-ui.doc :doc="\App\Support\Docs::media($card)" class="row"><x-ui.row-icon name="file" size="s" tone="urgent"/><span class="min-w-0 flex-1">Реквизиты от менеджера, фото{{ $cards->count() > 1 ? ' '.($n + 1) : '' }}</span><x-ui.chevron/></x-ui.doc>@endforeach
                </div>
            @endif
            <form method="post" action="/work/deals/{{ $deal->id }}/contract" class="flex flex-col gap-3" id="{{ $dataSheet }}-form">
                @csrf @method('put')
                <x-billing.buyer-fields :party="$buyerParty" :key="'crm-buyer-'.$deal->id" :scan="$dataSheet.'-form'" :fallback="$buyer->name">
                    {{-- Скан одним PDF — под плитками скана, а не над видом покупателя (06.11.2026). --}}
                    @if ($scanPdf)
                        <x-slot:scan-after>
                            <div class="list mt-3">
                                <x-ui.doc :doc="$scanPdf" class="row">
                                    <x-ui.row-icon name="file" size="s" tone="open"/>
                                    <span class="min-w-0 flex-1">Скан паспорта, PDF</span>
                                    <x-ui.chevron/>
                                </x-ui.doc>
                            </div>
                        </x-slot:scan-after>
                    @endif
                </x-billing.buyer-fields>
                <x-ui.sheet-foot>Сохранить</x-ui.sheet-foot>
            </form>
        </x-ui.sheet>
    </div>
@elseif ($buyer || ! $live)
    <div class="row">
        @if ($buyer)<x-ui.avatar :user="$buyer" :size="36"/>@endif
        <span class="min-w-0 flex-1">@if ($buyer)<span class="block truncate">{{ $who }}{{ $isManager ? ', менеджер' : '' }}</span>@if ($ready)<span class="row-sub text-open">{{ $kindWord }}данные есть</span>@endif @else<span class="text-ink-muted">Покупатель не выбран</span>@endif</span>
    </div>
    @if ($scanPdf)
        <x-ui.doc :doc="$scanPdf" class="row">
            <x-ui.row-icon name="file" size="s" tone="open"/>
            <span class="min-w-0 flex-1">Скан паспорта, PDF</span>
            <x-ui.chevron/>
        </x-ui.doc>
    @endif
@endif
@foreach ($cards as $n => $card)
    <x-ui.doc :doc="\App\Support\Docs::media($card)" class="row">
        <x-ui.row-icon name="file" size="s" tone="urgent"/>
        <span class="min-w-0 flex-1"><span class="block">Реквизиты от менеджера, фото{{ $cards->count() > 1 ? ' '.($n + 1) : '' }}</span>@if (! $ready)<span class="row-sub text-urgent">внести данные покупателя</span>@endif</span>
        <x-ui.chevron/>
    </x-ui.doc>
@endforeach
@if ($live && $buyer && ! $isManager && $deal->isPrime())
    <form method="post" action="/work/deals/{{ $deal->id }}/contract" class="contents" data-controller="autosubmit">
        @csrf @method('put')
        <label class="row row-check">
            <span class="min-w-0 flex-1">Платит покупатель</span>
            <span class="check"><input type="radio" name="payer" value="buyer" @checked(! $contract->managerPays()) data-action="change->autosubmit#submit"></span>
        </label>
        <label class="row row-check">
            <span class="min-w-0 flex-1">Платит менеджер</span>
            <span class="check"><input type="radio" name="payer" value="manager" @checked($contract->managerPays()) data-action="change->autosubmit#submit"></span>
        </label>
    </form>
@endif
@if ($live && ! ($deal->isGarage() && $buyer))
    @php
        $rows = \App\Support\People::contractBuyers($manager, $manager->name.', менеджер', 'сам покупает');
    @endphp
    <form method="post" action="/work/deals/{{ $deal->id }}/contract" class="contents">
        @csrf @method('put')
        <x-people.pick name="buyer_id" kind="buyer" passport variant="row" :people="$rows" :value="$isManager ? 'me' : $buyer?->id" title="Покупатель" :deal="$deal->id" link autosubmit :open="$ask && ! $buyer"
            :placeholder="$buyer ? 'Другой покупатель' : 'Выбрать покупателя'" finish="Выбрать"/>
    </form>
    @error('buyer_id')<p class="field-error px-4 py-2">{{ $message }}</p>@enderror
@endif
