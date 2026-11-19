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
    // Подписи строк («Покупатель — имя ›») — в карточке «Договор» (`labels`); в чек-листе шага пункт уже называется «Покупатель».
    $labels ??= false;
    $capped ??= false;
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
            @if ($ready || ! $editable)
                {{-- Строкой «Покупатель — имя ›», состояние под подписью (16.11.2026, «Договор» одной карточкой). --}}
                {{-- В «Оформлении» покупатель — одна строка (16.11.2026, владелец: «выбор покупателя — одно поле, а не два»): данные
                     есть — нажатие открывает выбор покупателя; нет — их шторку, «Другой покупатель» в ней. --}}
                @if ($labels && $editable && ! ($garage && $buyer) && ! $me->isSuperBuyer())
                <button type="button" @class(['row w-full text-left', 'sale-row' => ! $capped]) data-controller="emit" data-action="emit#send" data-emit-event-param="buyer:open">
                @else
                <a href="{{ $isMe ? '/account/money/details' : '/buyers/'.$buyer->id }}" @class(['row', 'sale-row' => $labels && ! $capped])>
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
                @if ($labels && $editable && ! ($garage && $buyer) && ! $me->isSuperBuyer())
                </button>
                @else
                </a>
                @endif
            @else
                <div data-controller="sheet" class="contents">
                    <x-ui.sheet-row @class(['sale-row' => $labels && ! $capped])>
                        @if ($labels && ! $capped)
                            <span class="sale-row-label !flex-none text-ink">Покупатель</span>
                            <span class="sale-row-value min-w-0 !max-w-none flex-1"><span class="block truncate">{{ $isMe ? ($garage ? 'Ваши данные' : 'Я сам') : $buyerName }}</span><span class="block text-sm text-urgent">{{ $gap ?? 'нужны данные' }}</span></span>
                        @elseif ($capped)
                            <x-ui.icon :name="$buyer->buyerIcon()" class="size-5 shrink-0 text-ink-dim"/><span class="min-w-0 flex-1 truncate">{{ $isMe ? ($garage ? 'Ваши данные' : 'Я сам') : $buyerName }}</span>
                        @else
                            <span class="min-w-0 flex-1"><span class="block truncate">{{ $isMe ? ($garage ? 'Ваши данные' : 'Я сам') : $buyerName }}</span><span class="block text-sm text-urgent">{{ $gap ?? 'нужны данные' }}</span></span>
                        @endif
                        <x-ui.chevron/>
                    </x-ui.sheet-row>
                    <x-ui.sheet id="deal-buyer-data" :title="'Данные для '.$for" :open="$dataErrors || session('open_sheet') === 'deal-buyer-data'" tall>
                        @php $buyerParty = $buyer->party ?? \App\Billing\Party::forUser($buyer); @endphp
                        <form method="post" action="/deals/{{ $deal->id }}/contract" class="flex flex-col gap-3" id="deal-buyer-data-form">
                            @csrf @method('put')
                            <x-billing.buyer-fields :party="$buyerParty" key="buyer-now" scan="deal-buyer-data-form" :fallback="$buyer->name === \App\Users\Actions\AddPerson::UNNAMED ? null : $buyer->name"/>
                            <x-ui.sheet-foot>Сохранить</x-ui.sheet-foot>
                        </form>
                        @if ($labels && ! ($garage && $buyer) && ! $me->isSuperBuyer())
                            <button type="button" class="btn btn-quiet btn-s mt-3 w-full" data-controller="emit" data-action="sheet#close emit#send" data-emit-event-param="buyer:open">Другой покупатель</button>
                        @endif
                    </x-ui.sheet>
                </div>
            @endif
            {{-- Кто платит по счёту — строкой «Плательщик» в «Деньгах» над способами оплаты (`x-deal.payer`, 16.11.2026). --}}
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
