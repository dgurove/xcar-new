{{-- Покупатель для договора у менеджера (05.10.2026): у ДКП — с кем подписывает собственник, у ПРАЙМ — кому счёт и ДКП
     ПРАЙМ. На странице — одна строка (кто, есть ли данные), выбор и данные — шторками, как в приложении, а не форма
     паспорта во весь экран. «Покупатель»: «Я сам» и свои покупатели строками с галкой (нажатие сохраняет), новый — формой
     внизу, «Пригласить по ссылке». Данных для договора нет — строка открывает «Данные для договора». У ПРАЙМ — кто платит
     по счёту: покупатель или менеджер за вычетом своего вознаграждения. Счёт и договор собираются сами
     (`SaveDealContract` → `SyncDealInvoices`). Шторку выбора открывает и задача («Укажите покупателя», событие `buyer:open`).
     Куски ($part): buyer — покупатель (и кто платит), doc — сам ДКП строкой (открыть, распечатать; не готов — чего нет),
     all — оба под заголовком. В задаче на шаге договора ($embedded) — пунктами чек-листа, без своего заголовка.
     07.10.2026: поля строками (.fields), кнопка .sheet-foot. --}}
@php
    $contract = \App\Offers\DealContract::for($deal)->loadMissing(['seller', 'buyer.party']);
    $buyer = $contract->buyer;
    $me = auth()->user();
    $isMe = $buyer && $buyer->id === $me->id;
    // Готов — с паспортом и его сканом у физлица (`Party::readyAsBuyer`, 11.10.2026); без скана — та же шторка данных.
    $ready = $buyer?->party?->readyAsBuyer();
    $gap = $buyer?->party?->buyerGap();
    $mine = \App\Support\People::buyersOf($me);
    $prime = $deal->isPrime();
    $editable = $deal->isLive();
    // Гаражная «платит менеджер»: ДКП ПРАЙМ с ним самим — выбирать покупателя нечего, нужны только его данные.
    $garage = $deal->isGarage();
    $current = $isMe ? 'me' : $buyer?->id;
    // Ошибки формы открывают ту шторку, откуда она ушла: поля у новой и у данных одни (`buyer.*`), различает `buyer_id`.
    // Только ошибки полей покупателя (12.11.2026: «Проверьте цену» шторки «Продаю» открывала в гараже «Данные для договора»).
    $buyerErrors = collect($errors->keys())->contains(fn ($k) => str_starts_with($k, 'buyer.') || str_starts_with($k, 'new_buyer.'));
    $newErrors = $buyerErrors && old('buyer_id') === 'new';
    $dataErrors = $buyerErrors && ! old('buyer_id') && ! old('payer');
    $part ??= 'all';
    $embedded ??= false;
    $doc = new \App\Offers\DkpDocument($contract);
    $pdf = ['url' => '/deals/'.$deal->id.'/dkp.pdf', 'type' => 'pdf', 'name' => $doc->fileName(), 'label' => 'ДКП'];
    $missing = $contract->missing();
    // Без договора — покупатель для счёта поставщика (03.11.2026, Альфа): «данные для счёта».
    $for = $deal->hasContract() ? 'договора' : 'счёта';
@endphp
<section @if ($part !== 'doc') id="dkp-buyer" @endif class="min-w-0">
    @unless ($embedded)<h2 class="list-head">{{ $garage ? 'Договор купли-продажи' : 'Покупатель' }}</h2>@endunless
    <div class="list">
        @if ($part !== 'doc')
        @if ($buyer)
            @if ($ready || ! $editable)
                <a href="{{ $isMe ? '/account/money/details' : '/buyers/'.$buyer->id }}" class="row">
                    <x-ui.avatar :user="$buyer" :size="36"/>
                    <span class="min-w-0 flex-1"><span class="block truncate">{{ $isMe ? ($garage ? 'Ваши данные' : 'Я сам') : ($buyer->party?->name ?: $buyer->name) }}</span>
                        <span class="row-sub">@if ($ready)<span class="text-open">данные есть</span>@else<span class="text-urgent">нужны данные для {{ $for }}</span>@endif</span></span>
                    <x-ui.chevron/>
                </a>
            @else
                <div data-controller="sheet" class="contents">
                    <x-ui.sheet-row>
                        <x-ui.avatar :user="$buyer" :size="36"/>
                        <span class="min-w-0 flex-1"><span class="block truncate">{{ $isMe ? ($garage ? 'Ваши данные' : 'Я сам') : ($buyer->party?->name ?: $buyer->name) }}</span>
                            <span class="row-sub text-urgent">{{ $gap ?? 'нужны данные для '.$for }}</span></span>
                        <x-ui.chevron/>
                    </x-ui.sheet-row>
                    <x-ui.sheet id="deal-buyer-data" :title="'Данные для '.$for" :open="$dataErrors || session('open_sheet') === 'deal-buyer-data'" tall>
                        @php $buyerParty = $buyer->party ?? \App\Billing\Party::forUser($buyer); @endphp
                        <form method="post" action="/deals/{{ $deal->id }}/contract" class="flex flex-col gap-3" id="deal-buyer-data-form">
                            @csrf @method('put')
                            <x-billing.buyer-fields :party="$buyerParty" key="buyer-now" scan="deal-buyer-data-form" :fallback="$buyer->name === \App\Users\Actions\AddPerson::UNNAMED ? null : $buyer->name"/>
                            <x-ui.sheet-foot>Сохранить</x-ui.sheet-foot>
                        </form>
                    </x-ui.sheet>
                </div>
            @endif
            @if ($prime && ! $isMe && $editable)
                {{-- Кто платит по счёту ПРАЙМ: покупатель (вознаграждение выплатим) или сам менеджер за вычетом своего. На
                     вариантах — суммы (06.10.2026): без пояснений видно, что «я» платит меньше. Вознаграждение менеджеру
                     открыто со счёта — до него суммы не пишем. --}}
                @php
                    $full = $deal->showsCommission() && $deal->amount ? (int) $deal->amount : null;
                    $net = $full !== null && $deal->commission_mode === \App\Offers\CommissionMode::Withheld ? $full - min((int) $deal->commission, max(0, $full - (int) $deal->cost)) : $full;
                @endphp
                {{-- Кто платит по счёту — две строки с галкой, как в Настройках (17.10.2026: пилюли «покупатель 980 000 ₽» и
                     «я 980 000 ₽» не влезали в ширину телефона, вторая обрезалась). --}}
                <form method="post" action="/deals/{{ $deal->id }}/contract" class="contents" data-controller="autosubmit">
                    @csrf @method('put')
                    <label class="row row-check">
                        <span class="min-w-0 flex-1">Платит покупатель</span>
                        @if ($full)<span class="nums shrink-0 text-ink-muted">{{ \App\Support\Money::rub($full) }}</span>@endif
                        <span class="check"><input type="radio" name="payer" value="buyer" @checked(! $contract->managerPays()) data-action="change->autosubmit#submit"></span>
                    </label>
                    <label class="row row-check">
                        <span class="min-w-0 flex-1">Плачу я</span>
                        @if ($net)<span class="nums shrink-0 text-ink-muted">{{ \App\Support\Money::rub($net) }}</span>@endif
                        <span class="check"><input type="radio" name="payer" value="manager" @checked($contract->managerPays()) data-action="change->autosubmit#submit"></span>
                    </label>
                </form>
            @endif
        @endif
        @if ($editable && ! ($garage && $buyer) && ! $me->isSuperBuyer())
            {{-- Выбор покупателя — шторкой `x-people.pick` (18.10.2026, владелец: «покупателя ещё нет — приходится идти в кабинет
                 в пользователей, это бред»): «Я сам», свои покупатели, «+ Новый покупатель» тут же — данными (пароль, вход) или
                 ссылкой, что помнит сделку. Выбор сразу сохраняется; данных для договора нет — следом их шторка со сканом. --}}
            @php
                $rows = \App\Support\People::contractBuyers($me, 'Я сам', 'ваши данные для договора');
            @endphp
            <form method="post" action="/deals/{{ $deal->id }}/contract" class="contents">
                @csrf @method('put')
                <x-people.pick name="buyer_id" kind="buyer" passport variant="row" :people="$rows" :value="$current" title="Покупатель" :deal="$deal->id" link autosubmit
                    :placeholder="$buyer ? 'Другой покупатель' : 'Выбрать покупателя'" finish="Выбрать" data-action="buyer:open@window->sheet#open"/>
            </form>
            @error('buyer_id')<p class="field-error px-4 py-2">{{ $message }}</p>@enderror
        @endif
        @endif
        @if ($part !== 'buyer')
            {{-- ДКП — документ, а не строка «Расчёта»: только готовый — открыть и распечатать (15.11.2026, владелец: «ЗАЧЕМ мы
                 показываем договор, если он НЕ ГОТОВ»; прежде — «ДКП готовим: (Э)ПТС»). --}}
            @if (! $missing)
                <x-ui.doc :doc="$pdf" class="row">
                    <x-ui.row-icon name="file" size="s" tone="open"/>
                    <span class="min-w-0 flex-1"><span class="block">{{ $prime ? 'ДКП '.$deal->seller()->short() : 'ДКП' }}{{ $doc->number() ? ' № '.$doc->number() : '' }}</span><span class="row-sub text-open">готов, распечатайте</span></span>
                    <x-ui.chevron/>
                </x-ui.doc>
            @endif
        @endif
    </div>
</section>
