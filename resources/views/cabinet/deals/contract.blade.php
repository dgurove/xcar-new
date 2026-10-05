{{-- Покупатель для договора у менеджера (05.10.2026): у ДКП — с кем подписывает собственник, у ПРАЙМ — кому счёт и ДКП
     ПРАЙМ. На странице — одна строка (кто, есть ли данные), выбор и данные — шторками, как в приложении, а не форма
     паспорта во весь экран. «Покупатель»: «Я сам» и свои покупатели строками с галкой (нажатие сохраняет), новый — формой
     внизу, «Пригласить по ссылке». Данных для договора нет — строка открывает «Данные для договора». У ПРАЙМ — кто платит
     по счёту: покупатель или менеджер за вычетом своего вознаграждения. Счёт и договор собираются сами
     (`SaveDealContract` → `SyncDealInvoices`). Шторку выбора открывает и задача («Укажите покупателя», событие `buyer:open`).
     Куски ($part): buyer — покупатель (и кто платит), doc — сам ДКП строкой (открыть, распечатать; не готов — чего нет),
     all — оба под заголовком. В задаче на шаге договора ($embedded) — пунктами чек-листа, без своего заголовка. --}}
@php
    $contract = \App\Offers\DealContract::for($deal)->loadMissing(['seller', 'buyer.party']);
    $buyer = $contract->buyer;
    $me = auth()->user();
    $isMe = $buyer && $buyer->id === $me->id;
    $ready = $buyer?->party?->readyForContract();
    $mine = $me->buyers()->orderBy('name')->get(['id', 'name']);
    $prime = $deal->isPrime();
    $editable = $deal->isActive();
    // Гаражная «платит менеджер»: ДКП ПРАЙМ с ним самим — выбирать покупателя нечего, нужны только его данные.
    $garage = $deal->isGarage();
    $current = $isMe ? 'me' : $buyer?->id;
    // Ошибки формы открывают ту шторку, откуда она ушла: поля у новой и у данных одни (`buyer.*`), различает `buyer_id`.
    $newErrors = $errors->any() && old('buyer_id') === 'new';
    $dataErrors = $errors->any() && ! old('buyer_id') && ! old('payer');
    $part ??= 'all';
    $embedded ??= false;
    $pdf = ['url' => '/deals/'.$deal->id.'/dkp.pdf', 'type' => 'pdf', 'name' => 'ДКП '.$deal->offer->titleWithYear().'.pdf', 'label' => 'ДКП'];
    $missing = $contract->missing();
    // Покупатель — пункт менеджера выше; у договора называем то, что вносим мы (продавец, документы ТС, цена).
    $ours = array_values(array_diff($missing, ['покупатель', 'данные покупателя']));
@endphp
<section @if ($part !== 'doc') id="dkp-buyer" @endif class="min-w-0">
    @unless ($embedded)<h2 class="list-head">{{ $garage ? 'Договор купли-продажи' : ($prime ? 'Покупатель: счёт и ДКП' : 'Покупатель по ДКП') }}</h2>@endunless
    <div class="list">
        @if ($part !== 'doc')
        @if ($buyer)
            @if ($ready || ! $editable)
                <a href="{{ $isMe ? '/account/money/details' : '/buyers/'.$buyer->id }}" class="row">
                    <span class="min-w-0 flex-1"><span class="block">{{ $isMe ? ($garage ? 'Ваши данные' : 'Я сам') : ($buyer->party?->name ?: $buyer->name) }}</span>
                        <span class="row-sub">@if ($ready)<span class="text-open">данные есть</span>@else<span class="text-urgent">нужны данные для договора</span>@endif</span></span>
                    <x-ui.chevron/>
                </a>
            @else
                <div data-controller="sheet" class="contents">
                    <button type="button" class="row w-full text-left" data-action="sheet#open">
                        <span class="min-w-0 flex-1"><span class="block">{{ $isMe ? ($garage ? 'Ваши данные' : 'Я сам') : ($buyer->party?->name ?: $buyer->name) }}</span>
                            <span class="row-sub text-urgent">нужны данные для договора</span></span>
                        <x-ui.chevron/>
                    </button>
                    <x-ui.sheet id="deal-buyer-data" title="Данные для договора" :open="$dataErrors">
                        <form method="post" action="/deals/{{ $deal->id }}/contract" class="flex flex-col gap-4">
                            @csrf @method('put')
                            <x-billing.buyer-fields :party="$buyer->party ?? new \App\Billing\Party(['name' => $buyer->name])" key="buyer-now"/>
                            <x-ui.button block>Сохранить</x-ui.button>
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
                <form method="post" action="/deals/{{ $deal->id }}/contract" class="row flex-wrap" data-controller="autosubmit">
                    @csrf @method('put')
                    <span class="min-w-0 flex-1">Платит по счёту</span>
                    <span class="flex gap-1.5">
                        <label class="choice"><input type="radio" name="payer" value="buyer" @checked(! $contract->managerPays()) data-action="change->autosubmit#submit"><span>покупатель@if ($full) <span class="nums">{{ \App\Support\Money::rub($full) }}</span>@endif</span></label>
                        <label class="choice"><input type="radio" name="payer" value="manager" @checked($contract->managerPays()) data-action="change->autosubmit#submit"><span>я@if ($net) <span class="nums">{{ \App\Support\Money::rub($net) }}</span>@endif</span></label>
                    </span>
                </form>
            @endif
        @endif
        @if ($editable && ! ($garage && $buyer))
            <div data-controller="sheet" data-action="buyer:open@window->sheet#open" class="contents">
                <button type="button" class="row w-full text-left" data-action="sheet#open">
                    <span @class(['min-w-0 flex-1', 'font-medium text-accent-text' => ! $buyer])>{{ $buyer ? 'Другой покупатель' : 'Выбрать покупателя' }}</span>
                    <x-ui.chevron/>
                </button>
                <x-ui.sheet id="deal-buyer" title="Покупатель" :open="$newErrors || $errors->has('buyer_id')">
                    <div class="flex flex-col gap-4">
                        <form method="post" action="/deals/{{ $deal->id }}/contract" data-controller="autosubmit">
                            @csrf @method('put')
                            <div class="list">
                                <label class="row row-check">
                                    <span class="min-w-0 flex-1">Я сам</span>
                                    <span class="check"><input type="radio" name="buyer_id" value="me" @checked($current === 'me') data-action="change->autosubmit#submit"></span>
                                </label>
                                @foreach ($mine as $b)
                                    <label class="row row-check">
                                        <span class="min-w-0 flex-1 break-words">{{ $b->name }}</span>
                                        <span class="check"><input type="radio" name="buyer_id" value="{{ $b->id }}" @checked($current === $b->id) data-action="change->autosubmit#submit"></span>
                                    </label>
                                @endforeach
                                <a href="/account/invites" class="row"><span class="min-w-0 flex-1">Пригласить по ссылке</span><x-ui.chevron/></a>
                            </div>
                            @error('buyer_id')<p class="field-error mt-2">{{ $message }}</p>@enderror
                        </form>
                        <details @if ($newErrors) open @endif>
                            <summary class="list-head cursor-pointer list-none">+ Новый покупатель</summary>
                            <form method="post" action="/deals/{{ $deal->id }}/contract" class="mt-2 flex flex-col gap-3">
                                @csrf @method('put')
                                <input type="hidden" name="buyer_id" value="new">
                                <x-ui.field name="new_buyer[phone]" label="Телефон" type="tel"/>
                                <x-billing.buyer-fields key="new-buyer"/>
                                <x-ui.button block>Добавить покупателя</x-ui.button>
                            </form>
                        </details>
                    </div>
                </x-ui.sheet>
            </div>
        @endif
        @endif
        @if ($part !== 'buyer')
            {{-- ДКП — документ, а не строка «Расчёта»: готов — открыть и распечатать; не готов — строка без ссылки и чего не
                 хватает (06.10.2026, владелец: «почему даём скачать договор, если не указан покупатель»). --}}
            @if (! $missing)
                <x-ui.doc :doc="$pdf" class="row">
                    <x-ui.row-icon name="file" size="s" tone="open"/>
                    <span class="min-w-0 flex-1"><span class="block">{{ $prime ? 'ДКП ПРАЙМ' : 'ДКП' }}</span><span class="row-sub text-open">готов, распечатайте</span></span>
                    <x-ui.chevron/>
                </x-ui.doc>
            @else
                <div class="row">
                    <x-ui.row-icon name="file" size="s" tone="muted"/>
                    <span class="min-w-0 flex-1"><span class="block text-ink-muted">{{ $prime ? 'ДКП ПРАЙМ' : 'ДКП' }}</span>
                        @php $need = array_values(array_filter([in_array('покупатель', $missing, true) ? 'выберите покупателя' : null, in_array('данные покупателя', $missing, true) ? 'нужны данные покупателя' : null, $ours ? 'готовим: '.implode(', ', $ours) : null])); @endphp
                        <span class="row-sub !whitespace-normal">{{ implode(', ', $need) }}</span></span>
                </div>
            @endif
        @endif
    </div>
</section>
