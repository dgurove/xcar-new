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
    $gap = $buyer?->party?->gapWord();
    $mine = \App\Support\People::buyersOf($me);
    $prime = $deal->isPrime();
    $editable = $deal->holderEditable();
    // Гаражная «платит менеджер»: ДКП ПРАЙМ с ним самим — выбирать покупателя нечего, нужны только его данные. Машина в гараже
    // покупателя (`forGarageBuyer`) — договор на его клиента (24.11.2026), выбор и «Другой покупатель» как у сделки (26.11.2026).
    $garage = $deal->isGarage() && ! $deal->forGarageBuyer();
    $current = $isMe ? 'me' : $buyer?->id;
    // Ошибки формы открывают ту шторку, откуда она ушла: поля у новой и у данных одни (`buyer.*`), различает `buyer_id`.
    // Только ошибки полей покупателя (12.11.2026: «Проверьте цену» шторки «Продаю» открывала в гараже «Данные для договора»).
    $buyerErrors = collect($errors->keys())->contains(fn ($k) => str_starts_with($k, 'buyer.') || str_starts_with($k, 'new_buyer.'));
    $newErrors = $buyerErrors && old('buyer_id') === 'new';
    $dataErrors = $buyerErrors && ! old('buyer_id') && ! old('payer');
    $part ??= 'all';
    $embedded ??= false;
    // Подписи строк («Покупатель — имя ›») — в карточке «Договор» (`labels`); в чек-листе шага пункт уже называется «Покупатель».
    $labels ??= false;
    $capped ??= false;
    // Можно сменить покупателя: карточка договора (`labels`), сделка держателя не закрыта, не «платит менеджер» гаража, не
    // супер-покупатель, и договор не подписан, по счёту не платили (`Deal::buyerLocked`, 26.11.2026).
    // Заготовка с плитки (`isBuyerDraft`) — её строка ведёт сразу в данные: плитки выбора и так над ней (`buyer-picker`).
    $canPick = $labels && $editable && ! ($garage && $buyer) && ! $me->isSuperBuyer() && ! ($buyer && $deal->buyerLocked()) && ! $buyer?->isBuyerDraft();
    $doc = new \App\Offers\DkpDocument($contract);
    $pdf = ['url' => '/deals/'.$deal->id.'/dkp.pdf', 'type' => 'pdf', 'name' => $doc->fileName(), 'label' => 'ДКП'];
    $missing = $contract->missing();
    // Без договора — покупатель для счёта поставщика (03.11.2026, Альфа): «данные для счёта».
    $for = $deal->hasContract() ? 'договора' : 'счёта';
    // Имя покупателя строкой: человек — как аккаунт («Михаил Шмелёв», ФИО целиком резалось), организация и ИП — коротко по названию.
    $buyerName = $buyer ? ($buyer->party?->kind === \App\Billing\PartyKind::Person || ! $buyer->party?->kind ? $buyer->buyerLabel()
        : (\App\Support\OrgName::short($buyer->party?->name) ?: $buyer->buyerLabel())) : null;
@endphp
<section @if ($part !== 'doc') id="dkp-buyer" @endif class="min-w-0">
    @unless ($embedded)<h2 class="list-head">{{ $garage ? 'Договор купли-продажи' : 'Покупатель' }}</h2>@endunless
    {{-- `capped` — подпись «Покупатель» над плашкой, в плашке имя и состояние (18.11.2026, владелец про карточку денег: «Покупатель
         над блоком, а не в блоке, чтобы имя нормально влезало»). --}}
    {{-- Состояние — справа в строке подписи, в плашке только имя (18.11.2026, владелец). --}}
    @if ($capped && $buyer)<div class="list-cap !px-0 flex justify-between gap-3"><span>Покупатель</span><span @class(['text-open' => $ready, 'text-urgent' => ! $ready])>{{ $ready ? 'данные есть' : ($gap ?? 'нужны данные') }}</span></div>@endif
    <div @class(["list", "list-solo" => $capped])>
        @if ($part !== 'doc')
        @if ($buyer)
            {{-- Строка «Покупатель — имя ›» (26.11.2026, владелец: «когда прикрепили покупателя, его невозможно убрать… продумать, как
                 красиво и удобно указывать другого»): можно сменить — нажатие открывает выбор (`deal-buyer-choose`: новый плитками
                 вида, текущий с его данными, из своих); нельзя (`Deal::buyerLocked`) или менять некому — данные, как было. --}}
            @if ($canPick)
            <button type="button" @class(['row w-full text-left', 'sale-row' => $labels && ! $capped]) data-controller="emit" data-action="emit#send" data-emit-event-param="deal-buyer-choose:open">
            @elseif ($ready || ! $editable)
            <a href="{{ $isMe ? '/account/money/details' : '/buyers/'.$buyer->id }}" @class(['row', 'sale-row' => $labels && ! $capped])>
            @else
            <button type="button" @class(['row w-full text-left', 'sale-row' => $labels && ! $capped]) data-controller="emit" data-action="emit#send" data-emit-event-param="deal-buyer-data:open">
            @endif
                @if ($labels && ! $capped)
                    {{-- Подпись слева, имя справа, состояние — под именем (18.11.2026: в узкой колонке ПК «данные есть» под подписью
                         рвалось на две строки, а имя резалось). --}}
                    <span class="sale-row-label !flex-none text-ink">Покупатель</span>
                    <span class="sale-row-value min-w-0 !max-w-none flex-1"><span class="block truncate">{{ $isMe ? ($garage ? 'Ваши данные' : 'Я сам') : $buyerName }}</span><span @class(['block text-sm', 'text-open' => $ready, 'text-urgent' => ! $ready])>{{ $ready ? 'данные есть' : ($gap ?? 'нужны данные') }}</span></span>
                @elseif ($capped)
                    <x-ui.icon :name="$buyer->buyerIcon()" class="size-5 shrink-0 text-ink-dim"/><span class="min-w-0 flex-1 truncate">{{ $isMe ? ($garage ? 'Ваши данные' : 'Я сам') : $buyerName }}</span>
                @else
                    <span class="min-w-0 flex-1"><span class="block truncate">{{ $isMe ? ($garage ? 'Ваши данные' : 'Я сам') : $buyerName }}</span><span @class(['block text-sm', 'text-open' => $ready, 'text-urgent' => ! $ready])>{{ $ready ? 'данные есть' : ($gap ?? 'нужны данные') }}</span></span>
                @endif
                <x-ui.chevron/>
            @if ($canPick || (! $ready && $editable))
            </button>
            @else
            </a>
            @endif
            @if ($canPick)
                {{-- Выбор покупателя: новый — плитками вида (черновик `buyer-draft`, хоть физлицо, хоть организация), текущий — его
                     данные, из своих — список с поиском (`buyer:open`). --}}
                <div class="contents" data-controller="sheet" data-action="deal-buyer-choose:open@window->sheet#open">
                    <x-ui.sheet id="deal-buyer-choose" title="Покупатель">
                        <div class="list-cap !px-0">Новый покупатель</div>
                        <x-deal.buyer-kind :deal="$deal"/>
                        <div class="list-cap !px-0 mt-4">Сейчас</div>
                        <div class="list">
                            <button type="button" class="row w-full text-left" data-controller="emit" data-action="sheet#close emit#send" data-emit-event-param="deal-buyer-data:open">
                                <x-ui.icon :name="$buyer->buyerIcon()" class="size-5 shrink-0 text-ink-dim"/>
                                <span class="min-w-0 flex-1"><span class="block truncate">{{ $isMe ? ($garage ? 'Ваши данные' : 'Я сам') : $buyerName }}</span><span @class(['row-sub', 'text-open' => $ready, 'text-urgent' => ! $ready])>{{ $ready ? 'данные есть' : ($gap ?? 'нужны данные') }}</span></span>
                                <span class="shrink-0 text-sm text-accent-text">Данные</span><x-ui.chevron/>
                            </button>
                            <button type="button" class="row w-full text-left" data-controller="emit" data-action="sheet#close emit#send" data-emit-event-param="buyer:open">
                                <x-ui.row-icon name="users" size="s"/><span class="min-w-0 flex-1">Из своих покупателей</span><x-ui.chevron/>
                            </button>
                        </div>
                    </x-ui.sheet>
                </div>
            @endif
            @if ($canPick || (! $ready && $editable))
                <div class="contents" data-controller="sheet" data-action="deal-buyer-data:open@window->sheet#open">
                    <x-ui.sheet id="deal-buyer-data" :title="'Данные для '.$for" :open="$dataErrors || session('open_sheet') === 'deal-buyer-data'" tall>
                        @php $buyerParty = $buyer->party ?? \App\Billing\Party::forUser($buyer); @endphp
                        <form method="post" action="/deals/{{ $deal->id }}/contract" class="flex flex-col gap-3" id="deal-buyer-data-form">
                            @csrf @method('put')
                            {{-- Вид у заведённого покупателя — только физлицо или ИП (тот же человек); организация — другой покупатель,
                                 плиткой «Юр. лицо» (26.11.2026: сегмент превращал физлицо в организацию во всех его сделках). --}}
                            <x-billing.buyer-fields :party="$buyerParty" key="buyer-now" scan="deal-buyer-data-form" :fallback="$buyer->name === \App\Users\Actions\AddPerson::UNNAMED ? null : $buyer->name" :kinds="$buyer->isBuyerDraft() ? null : $buyerParty->sameKinds()"/>
                            <x-ui.sheet-foot>Сохранить</x-ui.sheet-foot>
                        </form>
                    </x-ui.sheet>
                </div>
            @endif
            {{-- Кто платит по счёту — строкой «Плательщик» в «Деньгах» над способами оплаты (`x-deal.payer`, 16.11.2026). --}}
        @endif
        @if ($editable && ! ($garage && $buyer) && ! $me->isSuperBuyer() && ! ($buyer && $deal->buyerLocked()))
            {{-- Выбор покупателя — шторкой `x-people.pick` (18.10.2026, владелец: «покупателя ещё нет — приходится идти в кабинет
                 в пользователей, это бред»): «Я сам», свои покупатели, «+ Новый покупатель» тут же — данными (пароль, вход) или
                 ссылкой, что помнит сделку. Выбор сразу сохраняется; данных для договора нет — следом их шторка со сканом. --}}
            @php
                $rows = \App\Support\People::contractBuyers($me, 'Я сам', 'ваши данные для договора');
            @endphp
            <form method="post" action="/deals/{{ $deal->id }}/contract" class="contents">
                @csrf @method('put')
                <x-people.pick name="buyer_id" kind="buyer" passport :variant="$labels && $buyer ? 'none' : 'row'" :people="$rows" :value="$current" title="Покупатель" :deal="$deal->id" link autosubmit
                    :placeholder="$buyer ? 'Другой покупатель' : ($labels ? 'не выбран' : 'Выбрать покупателя')" :label="$buyer || ! $labels ? null : 'Покупатель'" finish="Выбрать" data-action="buyer:open@window->sheet#open"/>
            </form>
            @error('buyer_id')<p class="field-error px-4 py-2">{{ $message }}</p>@enderror
        @endif
        @endif
        @if ($part !== 'buyer')
            {{-- ДКП — документ, а не строка «Расчёта»: только готовый — открыть и распечатать (15.11.2026, владелец: «ЗАЧЕМ мы
                 показываем договор, если он НЕ ГОТОВ»; прежде — «ДКП готовим: (Э)ПТС»). --}}
            {{-- Готовый, но не отправленный сотрудником (кнопка в CRM, 16.11.2026) — «готовится», файла нет. --}}
            @if (! $missing && ! $contract->shownToManager())
                <div class="row sale-row"><span class="sale-row-label">Договор</span><span class="sale-row-value text-ink-muted">в обработке</span></div>
            @elseif (! $missing && $labels)
                {{-- В «Договоре и счёте» — файлом PDF со «Скачать» (16.11.2026, владелец: «чтобы поняли, что это файл»). --}}
                <x-deal.dkp-file :deal="$deal" :title="'Договор'.($doc->number() ? ' № '.$doc->number() : ' купли-продажи')" class="row"/>
            @elseif ($labels)
                <div class="row sale-row"><span class="sale-row-label">Договор</span><span @class(['sale-row-value', 'text-ink-muted' => $ready, 'text-danger' => ! $ready])>{{ $ready ? 'в обработке' : 'нужны данные' }}</span></div>
            @elseif (! $missing)
                <x-ui.doc :doc="$pdf" class="row sale-row">
                    <span class="sale-row-label text-ink">{{ $prime ? 'ДКП '.$deal->seller()->short() : 'ДКП' }}{{ $doc->number() ? ' № '.$doc->number() : '' }}</span>
                    <span class="sale-row-value text-open">готов</span>
                    <x-ui.chevron/>
                </x-ui.doc>
            @endif
        @endif
        {{-- Строки, что карточка «Договор» добавляет в ту же группу («Подписанный — Приложить», `cabinet.deals.buyer-block`). --}}
        {!! $after ?? '' !!}
    </div>
</section>
