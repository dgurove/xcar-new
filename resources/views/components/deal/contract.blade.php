{{-- ДКП сделки «страхователю по ДКП» для сотрудника (05.10.2026): чего договору не хватает и форма — продавец и ТС; покупателя вносит менеджер или админ за него (20.10.2026,
     шторкой `x-people.pick`). У ПРАЙМ продавец — ПРАЙМ,
     формы продавца нет. Договор — PDF в шторке
     документов (`DealContractController::document`). Договор и покупатель — строками (в CRM — внутри «Продажи», `bare`), поля — шторкой «Данные договора» (15.11.2026; до того
     «Изменить» карточки «ДКП ПРАЙМ»), ошибка формы открывает её сама.
     ДКП ПРАЙМ (14.11.2026): строкой — собственник по договору комиссии (сам из письма, `FillCommissionOwner`); под «Изменить» —
     собственник полями паспорта и № договора комиссии. НДС договора — рядом со схемой в «Деньгах» (`x-deal.scheme`). --}}
@props(['deal', 'bare' => false, 'docRow' => true, 'capped' => false])
@php
    use App\Offers\DealContract;
    $contract = DealContract::for($deal)->loadMissing(['seller', 'buyer.party']);
    $missing = $contract->missing();
    $doc_row = $docRow;
    // Поля договора — шторкой «Данные договора» (15.11.2026): открыта сама при ошибке формы или «Заполнить» из шага пути (`?dkp=1`),
    // пока чего-то не хватает; иначе — пунктом «⋯» «Продажи» (`dkp-{id}:open`) или «Изменить» окна модератора.
    $editing = $errors->hasAny(['seller.*', 'vehicle.*']) || (request()->boolean('dkp') && $missing);
    $form = 'dkp-'.$deal->id;
    $doc = new \App\Offers\DkpDocument($contract);
    $pdf = ['url' => '/work/deals/'.$deal->id.'/dkp.pdf', 'type' => 'pdf', 'name' => $doc->fileName(), 'label' => 'ДКП'];
    $prime = $contract->form() === 'prime';
    $owner = $contract->seller;
    // Собственник по договору комиссии — своей строкой и шторкой «Собственник» (`x-deal.owner`, 25.11.2026: физлицо, ИП, организация);
    // здесь — только продавец ДКП и ПРАЙМ без договора комиссии (вписали собственника — договор станет комиссионным).
    $ownerFields = $deal->isDkp() || ($prime && ! $contract->commissioned());
@endphp
@php ob_start(); @endphp
    {{-- Договор — файлом (`x-deal.contract-doc`); в «Продаже» его ставит `x-deal.money` над «Подписанным договором». --}}
    @if ($doc_row)<x-deal.contract-doc :deal="$deal"/>@endif
    <x-deal.buyer :deal="$deal" :contract="$contract" :capped="$capped"/>
@php $rows = ob_get_clean(); @endphp
@php ob_start(); @endphp
    <div class="contents" data-controller="sheet" data-action="dkp-{{ $deal->id }}:open@window->sheet#open">
        <x-ui.sheet :id="'dkp-sheet-'.$deal->id" title="Данные договора" :open="$editing">
            <form method="post" action="/work/deals/{{ $deal->id }}/contract" id="{{ $form }}" class="flex flex-col gap-4">
                @csrf @method('put')
                @if ($ownerFields)
                    <div class="list-cap !px-0">{{ $deal->isDkp() ? 'Продавец, собственник по СТС' : 'Собственник, комитент по договору комиссии' }}</div>
                    {{-- Скан паспорта продавца — из письма страховой сам (`FillOwnerFromPassport`, 25.11.2026) или руками; прочитанное — в поля. --}}
                    @if ($deal->isDkp() && $contract->seller)<x-billing.passport-scan :party="$contract->seller" :form="$form" prefix="seller"/>@endif
                    <x-billing.passport-fields :party="$contract->seller" prefix="seller"/>
                @endif
                <div class="grid grid-cols-1 gap-3">
                    {{-- У ПРАЙМ и Р.О.С. сумма договора считается из цены продажи (`DealContract::priceFor`, шторка `x-deal.price-money`). --}}
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
