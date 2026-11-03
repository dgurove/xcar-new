{{-- Покупатель сделки в CRM — выбирает менеджер, админ может за него (20.10.2026, владелец: «пока менеджер не укажет покупателя, мы
     не можем закрыть сделку»; «такой же удобный выбор и создание покупателя»): та же шторка `x-people.pick`, что у менеджера, —
     сам менеджер, его покупатели, «+ Новый покупатель» (встаёт в «Покупатели» менеджера и в сделку); нет данных — их шторка со
     сканом паспорта; у ПРАЙМ — кто платит по счёту. Строками внутри `.list`: в ДКП (`x-deal.contract`) и в «Покупателе для
     счёта» поставщика (`x-deal.invoice-buyer`, 03.11.2026). --}}
@props(['deal', 'contract' => null])
@php
    $contract ??= \App\Offers\DealContract::for($deal)->loadMissing(['buyer.party']);
    $manager = $deal->buyer;
    $buyer = $contract->buyer;
    $live = $deal->isActive() && ! $deal->isDirect() && $manager?->isManager();
    $isManager = $buyer && $buyer->id === $deal->buyer_id;
    $ready = $buyer?->party?->readyAsBuyer();
    $noScan = $buyer?->party?->readyForContract() && ! $ready;
    $who = $buyer ? ($buyer->party?->name ?: $buyer->name) : null;
    $dataSheet = 'deal-buyer-data-'.$deal->id;
    // Без договора (поставщик выставит счёт на покупателя, 03.11.2026) — «данные для счёта».
    $for = $deal->hasContract() ? 'договора' : 'счёта';
@endphp
@if ($buyer && $live && ! $ready)
    <div data-controller="sheet" class="contents">
        <x-ui.sheet-row>
            <span class="shrink-0 text-ink-muted">Покупатель</span>
            <span class="min-w-0 flex-1 text-right"><span class="block truncate">{{ $who }}{{ $isManager ? ', менеджер' : '' }}</span><span class="block text-sm text-urgent">{{ $noScan ? 'нужен скан паспорта' : 'нужны данные для '.$for }}</span></span>
            <x-ui.chevron/>
        </x-ui.sheet-row>
        <x-ui.sheet :id="$dataSheet" :title="'Данные для '.$for" :open="session('open_sheet') === $dataSheet || $errors->hasAny(['buyer.*'])" tall>
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
