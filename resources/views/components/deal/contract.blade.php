{{-- ДКП сделки «страхователю по ДКП» для сотрудника (05.10.2026): чего договору не хватает, сканы из писем страховой
     (паспорт собственника, СТС, ПТС) и форма — продавец и ТС; покупателя вносит менеджер или админ за него (20.10.2026,
     шторкой `x-people.pick`). У ПРАЙМ продавец — ПРАЙМ,
     формы продавца нет. Договор — PDF в шторке
     документов (`DealContractController::document`). Свёрнуто — договор и покупатель строками, сканы и поля — по «Изменить»
     (07.10.2026, владелец: «слишком много полей»; `edit_card_controller`), ошибка формы открывает их сама. --}}
@props(['deal'])
@php
    use App\Offers\DealContract;
    use App\Support\Docs;
    $contract = DealContract::for($deal)->loadMissing(['seller', 'buyer.party']);
    $missing = $contract->missing();
    $scans = $deal->offer->papers()->filter(fn ($m) => $m->getCustomProperty('letter'))->values();
    // Открыта полями: ошибка формы или «Заполнить» из шага пути (`?dkp=1`), пока чего-то не хватает — сохранили всё, и та же ссылка
    // (`back()` с `?dkp=1`) показывает договор готовым.
    $editing = $errors->hasAny(['seller.*', 'vehicle.*']) || (request()->boolean('dkp') && $missing);
    $form = 'dkp-'.$deal->id;
    $doc = new \App\Offers\DkpDocument($contract);
    $car = $contract->car();
    $pdf = ['url' => '/work/deals/'.$deal->id.'/dkp.pdf', 'type' => 'pdf', 'name' => $doc->fileName(), 'label' => 'ДКП'];
@endphp
<x-ui.card :title="$deal->isPrime() ? 'ДКП '.$deal->seller()->short() : 'ДКП'" id="dkp" class="scroll-mt-24" {{ $attributes }} data-controller="edit-card" :data-edit-card-form-value="$form" :data-edit-card-editing-value="$editing ? 'true' : null">
    <x-slot:actions><button type="button" class="edit-card-toggle" data-edit-card-target="button" data-action="edit-card#toggle">{{ $editing ? 'Готово' : 'Изменить' }}</button></x-slot:actions>
    <div class="list">
        <x-ui.doc :doc="$pdf" class="row">
            <span class="min-w-0 flex-1">
                <span class="block">Договор купли-продажи{{ $doc->number() ? ' № '.$doc->number() : '' }}</span>
                <span class="row-sub">@if ($missing)<span class="text-urgent">нет: {{ implode(', ', $missing) }}</span>@else<span class="text-open">готов</span>@endif</span>
            </span>
            <x-ui.icon name="file" class="size-4 shrink-0 text-ink-dim"/>
        </x-ui.doc>
        {{-- Договор ждёт ПТС — к его карточке «ПТС и СТС» под «Транспортным средством» (19.10.2026). --}}
        @if (in_array('(Э)ПТС', $missing, true))
            <a href="#car-docs" class="row"><span class="min-w-0 flex-1 text-urgent">Внести ПТС и СТС</span><x-ui.chevron/></a>
        @endif
        {{-- Дата договора — сама календарь (19.10.2026): у ДКП юрлица встаёт сама, когда договор собрался, у собственника —
             когда подписывают; правит и менеджер у себя. --}}
        <div class="row">
            <span class="shrink-0 text-ink-muted">Дата договора</span>
            <span class="min-w-0 flex-1 text-right"><x-ui.date chip submit :clearable="false" name="signed_at" :id="'dkp-date-field-'.$deal->id" :value="$contract->signed_at" :form="'dkp-date-'.$deal->id" placeholder="Указать" class="text-accent-text"/></span>
        </div>
        {{-- Покупатель — выбирает менеджер, админ может за него (20.10.2026, владелец: «пока менеджер не укажет покупателя, мы
             не можем закрыть сделку»; «такой же удобный выбор и создание покупателя»): та же шторка `x-people.pick`, что у
             менеджера, — сам менеджер, его покупатели, «+ Новый покупатель» (встаёт в «Покупатели» менеджера и в сделку);
             нет данных для договора — их шторка со сканом паспорта; у ПРАЙМ — кто платит по счёту. --}}
        @php
            $manager = $deal->buyer;
            $buyer = $contract->buyer;
            $live = $deal->isActive() && ! $deal->isDirect() && $manager?->isManager();
            $isManager = $buyer && $buyer->id === $deal->buyer_id;
            $ready = $buyer?->party?->readyAsBuyer();
            $noScan = $buyer?->party?->readyForContract() && ! $ready;
            $who = $buyer ? ($buyer->party?->name ?: $buyer->name) : null;
            $dataSheet = 'deal-buyer-data-'.$deal->id;
        @endphp
        @if ($buyer && $live && ! $ready)
            <div data-controller="sheet" class="contents">
                <x-ui.sheet-row>
                    <span class="shrink-0 text-ink-muted">Покупатель</span>
                    <span class="min-w-0 flex-1 text-right"><span class="block truncate">{{ $who }}{{ $isManager ? ', менеджер' : '' }}</span><span class="block text-sm text-urgent">{{ $noScan ? 'нужен скан паспорта' : 'нужны данные для договора' }}</span></span>
                    <x-ui.chevron/>
                </x-ui.sheet-row>
                <x-ui.sheet :id="$dataSheet" title="Данные для договора" :open="session('open_sheet') === $dataSheet || $errors->hasAny(['buyer.*'])" tall>
                    @php $buyerParty = $buyer->party ?? \App\Billing\Party::forUser($buyer); @endphp
                    @if ($buyerParty->isPerson())<x-billing.passport-scan :party="$buyerParty" :form="$dataSheet.'-form'"/>@endif
                    <form method="post" action="/work/deals/{{ $deal->id }}/contract" class="flex flex-col gap-3" id="{{ $dataSheet }}-form">
                        @csrf @method('put')
                        <x-billing.buyer-fields :party="$buyerParty" :key="'crm-buyer-'.$deal->id"/>
                        <x-ui.sheet-foot>Сохранить</x-ui.sheet-foot>
                    </form>
                </x-ui.sheet>
            </div>
        @elseif ($buyer || ! $live)
            <div class="row">
                <span class="shrink-0 text-ink-muted">Покупатель</span>
                <span class="min-w-0 flex-1 text-right">@if ($buyer)<span class="block truncate">{{ $who }}{{ $isManager ? ', менеджер' : '' }}</span>@if ($ready)<span class="block text-sm text-open">данные есть</span>@endif @else<span class="text-ink-muted">не выбран</span>@endif</span>
            </div>
        @endif
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
                $rows = collect([['name' => $manager->name.', менеджер'] + \App\Support\People::row($manager, $manager->party?->readyAsBuyer() ? 'данные для договора есть' : 'сам покупает')])->map(fn ($r) => ['id' => 'me'] + $r)
                    ->concat($manager->buyers()->with('party')->orderBy('name')->get()->map(fn ($b) => \App\Support\People::row($b, $b->party?->readyAsBuyer() ? 'данные для договора есть' : ($b->phone ? \App\Support\Phone::format($b->phone) : 'данных для договора нет'))));
            @endphp
            <form method="post" action="/work/deals/{{ $deal->id }}/contract" class="contents">
                @csrf @method('put')
                <x-people.pick name="buyer_id" kind="buyer" variant="row" :people="$rows" :value="$isManager ? 'me' : $buyer?->id" title="Покупатель" :deal="$deal->id" link autosubmit
                    :placeholder="$buyer ? 'Другой покупатель' : 'Выбрать покупателя'" finish="Выбрать"/>
            </form>
            @error('buyer_id')<p class="field-error px-4 py-2">{{ $message }}</p>@enderror
        @endif
    </div>
    <form method="post" action="/work/deals/{{ $deal->id }}/contract" id="dkp-date-{{ $deal->id }}" class="hidden">@csrf @method('put')</form>
    <div data-edit-card-target="view" @if ($editing) hidden @endif></div>
    <div data-edit-card-target="edit" @unless ($editing) hidden @endunless>
    @if ($scans->isNotEmpty())
        <div class="list-cap mt-4">Сканы страховой</div>
        <div class="list">
            @foreach ($scans as $m)<x-ui.doc :doc="Docs::media($m)" class="row"><span class="min-w-0 flex-1 truncate">{{ $m->file_name }}</span><x-ui.icon name="file" class="size-4 shrink-0 text-ink-dim"/></x-ui.doc>@endforeach
        </div>
    @endif
    <form method="post" action="/work/deals/{{ $deal->id }}/contract" id="{{ $form }}" class="mt-4 flex flex-col gap-4" data-controller="save-bar">
        @csrf @method('put')
        @if ($deal->isDkp())
            <div class="list-cap">Продавец, собственник по СТС</div>
            <x-billing.passport-fields :party="$contract->seller" prefix="seller"/>
        @endif
        <div class="list-cap">Договор</div>
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <x-ui.field name="vehicle[price]" label="Цена по ДКП, ₽" :value="$contract->price ? \App\Support\Money::nums($contract->price) : null"/>
            <x-ui.field name="vehicle[city]" label="Город договора" :value="$contract->city" :placeholder="$doc->city()"/>
        </div>
        <x-ui.save-bar page/>
    </form>
    </div>
</x-ui.card>
