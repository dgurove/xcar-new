{{-- ДКП сделки «страхователю по ДКП» для сотрудника (05.10.2026): чего договору не хватает, сканы из писем страховой
     (паспорт собственника, СТС, ПТС) и форма — продавец и ТС; покупателя вносит менеджер. У ПРАЙМ продавец — ПРАЙМ,
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
        <div class="row">
            <span class="shrink-0 text-ink-muted">Покупатель</span>
            <span class="min-w-0 flex-1 text-right">{{ $contract->buyer ? ($contract->buyer->party?->name ?: $contract->buyer->name) : 'выбирает менеджер' }}</span>
        </div>
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
