{{-- ДКП сделки «страхователю по ДКП» для сотрудника (05.10.2026): чего договору не хватает и форма — продавец и ТС; покупателя вносит менеджер или админ за него (20.10.2026,
     шторкой `x-people.pick`). У ПРАЙМ продавец — ПРАЙМ,
     формы продавца нет. Договор — PDF в шторке
     документов (`DealContractController::document`). Договор и покупатель — строками (в CRM — внутри «Продажи», `bare`), поля — шторкой «Данные договора» (15.11.2026; до того
     «Изменить» карточки «ДКП ПРАЙМ»), ошибка формы открывает её сама.
     ДКП ПРАЙМ (14.11.2026): строкой — собственник по договору комиссии (сам из письма, `FillCommissionOwner`); под «Изменить» —
     собственник полями паспорта и № договора комиссии. НДС договора — рядом со схемой в «Деньгах» (`x-deal.scheme`). --}}
@props(['deal', 'bare' => false])
@php
    use App\Offers\DealContract;
    $contract = DealContract::for($deal)->loadMissing(['seller', 'buyer.party']);
    $missing = $contract->missing();
    // Поля договора — шторкой «Данные договора» (15.11.2026): открыта сама при ошибке формы или «Заполнить» из шага пути (`?dkp=1`),
    // пока чего-то не хватает; иначе — пунктом «⋯» «Продажи» (`dkp-{id}:open`) или «Изменить» окна модератора.
    $editing = $errors->hasAny(['seller.*', 'vehicle.*']) || (request()->boolean('dkp') && $missing);
    $form = 'dkp-'.$deal->id;
    $doc = new \App\Offers\DkpDocument($contract);
    $pdf = ['url' => '/work/deals/'.$deal->id.'/dkp.pdf', 'type' => 'pdf', 'name' => $doc->fileName(), 'label' => 'ДКП'];
    $prime = $contract->form() === 'prime';
    $owner = $contract->seller;
    // Собственник-организация (из договора комиссии) полями паспорта не правится.
    $ownerFields = $deal->isDkp() || ($prime && (! $owner || $owner->kind === \App\Billing\PartyKind::Person));
@endphp
@php ob_start(); @endphp
    {{-- Договор — файлом, как документ: значок PDF и номер; чего не хватает — красным под ним. --}}
    <x-ui.doc :doc="$pdf" class="row">
        <x-ui.file-icon name="dkp.pdf"/>
        <span class="min-w-0 flex-1">
            <span class="block">Договор{{ $doc->number() ? ' № '.$doc->number() : ' купли-продажи' }}</span>
            {{-- Готов ли — словом; чего не хватает, делами в «Ходе сделки» (15.11.2026, владелец: рыжий список «нет: (Э)ПТС,
                 покупатель» и «Внести ПТС» под ним — «куча рыжего непонятного текста, ещё и с дубликатами»). --}}
            <span @class(['row-sub', 'text-open' => ! $missing])>{{ $missing ? 'не готов' : 'готов' }}</span>
        </span>
        <x-ui.chevron/>
    </x-ui.doc>
    {{-- Дата договора — сама календарь (19.10.2026): у ДКП юрлица встаёт сама, когда договор собрался, у собственника —
         когда подписывают; правит и менеджер у себя. --}}
    <div class="row">
        <span class="shrink-0 text-ink-muted">Дата договора</span>
        <span class="min-w-0 flex-1 text-right"><x-ui.date chip submit :clearable="false" name="signed_at" :id="'dkp-date-field-'.$deal->id" :value="$contract->signed_at" :form="'dkp-date-'.$deal->id" placeholder="Указать" class="{{ $contract->signed_at ? '' : 'text-accent-text' }}"/></span>
    </div>
    <x-deal.buyer :deal="$deal" :contract="$contract"/>
@php $rows = ob_get_clean(); @endphp
@php ob_start(); @endphp
    <form method="post" action="/work/deals/{{ $deal->id }}/contract" id="dkp-date-{{ $deal->id }}" class="hidden">@csrf @method('put')</form>
    <div class="contents" data-controller="sheet" data-action="dkp-{{ $deal->id }}:open@window->sheet#open">
        <x-ui.sheet :id="'dkp-sheet-'.$deal->id" title="Данные договора" :open="$editing">
            <form method="post" action="/work/deals/{{ $deal->id }}/contract" id="{{ $form }}" class="flex flex-col gap-4">
                @csrf @method('put')
                @if ($ownerFields)
                    <div class="list-cap !px-0">{{ $deal->isDkp() ? 'Продавец, собственник по СТС' : 'Собственник, комитент по договору комиссии' }}</div>
                    <x-billing.passport-fields :party="$contract->seller" prefix="seller"/>
                @endif
                <div class="grid grid-cols-1 gap-3">
                    {{-- У ПРАЙМ и Р.О.С. сумма договора — строкой «Сумма» в «Продаже» (`x-deal.contract-price`, 14.11.2026). --}}
                    @unless ($prime || $contract->form() === 'ros')
                        <x-ui.field name="vehicle[price]" label="Цена по ДКП, ₽" :value="$contract->price ? \App\Support\Money::nums($contract->price) : null"/>
                    @endunless
                    <x-ui.field name="vehicle[city]" label="Город договора" :value="$contract->city" :placeholder="$doc->city()"/>
                    @if ($prime)
                        <x-ui.field name="commission_no" label="Договор комиссии №" :value="$contract->commission_no" :placeholder="$contract->commissionNo() ?? 'нет'"/>
                    @endif
                </div>
                <x-ui.sheet-foot>Сохранить</x-ui.sheet-foot>
            </form>
        </x-ui.sheet>
    </div>
@php $sheet = ob_get_clean(); @endphp
@if ($bare)
    {{-- Внутри «Продажи» (`x-deal.money`): строки её списка, шторка — по пункту «⋯» «Данные договора…». --}}
    <div class="contents" id="dkp">{!! $rows !!}</div>
    {!! $sheet !!}
@else
    <x-ui.card title="Договор" id="dkp" class="scroll-mt-24" {{ $attributes }}>
        <x-slot:actions><button type="button" class="edit-card-toggle" data-controller="emit" data-action="emit#send" data-emit-event-param="dkp-{{ $deal->id }}:open">Изменить</button></x-slot:actions>
        <div class="list">{!! $rows !!}</div>
        {!! $sheet !!}
    </x-ui.card>
@endif
