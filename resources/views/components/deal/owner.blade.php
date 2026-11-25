{{-- Собственник (комитент) по договору комиссии ПРАЙМ — строкой группы «Договор» в «Продаже» и шторкой (25.11.2026, владелец: «может
     быть физлицо, юрлицо, ИП»; «иногда да, Совком, иногда нет, иногда неправильно распознаётся — чтобы красиво и удобно указывалось
     и менялось в CRM»). Строка — имя с видом, не готов для ДКП — красным. Шторка — выбор строками, как «Покупатель»: сам поставщик
     (его реквизиты, одна сторона на все сделки), распознанное из «Договора комиссии» в письмах (если отличается), нынешний с
     карандашом (поправить полями) и «Другой собственник» — физлицо, ИП или организация (`x-billing.buyer-fields`). Сервер —
     `PUT /work/deals/{id}/owner` → `SetDealOwner`. Открывает и событие `deal-owner-{id}:open` (окно «Договор невозможно сформировать»). --}}
@props(['deal', 'editable' => true])
@php
    use App\Billing\PartyKind;
    use App\Offers\Actions\FillCommissionOwner;
    use App\Offers\Actions\SetDealOwner;
    use App\Offers\DealContract;
    $contract = DealContract::for($deal)->loadMissing('seller');
    $owner = $contract->seller;
    $ready = $contract->ownerReady();
    $id = 'deal-owner-'.$deal->id;
    $action = '/work/deals/'.$deal->id.'/owner';
    $value = $owner ? SetDealOwner::label($owner) : 'не указан';
    $kindWord = fn ($p) => match ($p->kind) { PartyKind::Entrepreneur => 'ИП', PartyKind::Company => 'Юрлицо', default => 'Физлицо' };
    // У ИП «ИП» уже в имени — во второй строке только ИНН.
    $sub = fn ($p) => ($p->kind === PartyKind::Entrepreneur ? '' : $kindWord($p).', ').($p->kind === PartyKind::Person
        ? ($p->passport ? 'паспорт '.$p->passport : 'нет паспорта')
        : ($p->inn ? 'ИНН '.$p->inn : 'нет ИНН'));
    $can = $editable && auth()->user()?->canEditDeals();
    $vendor = $can ? SetDealOwner::vendorParty($deal) : null;
    // Распознанное из договора комиссии — разбор файлов не бесплатный: час на сделку, пока предложение не менялось.
    $found = $can ? \Illuminate\Support\Facades\Cache::remember('deal-owner-found:'.$deal->id.':'.$deal->offer->updated_at?->timestamp, 3600,
        fn () => app(FillCommissionOwner::class)($deal, apply: false, force: true)) : null;
    $same = fn (array $f, $p) => $p && (($f['party']['inn'] ?? null) ? ($f['party']['inn'] === $p->inn) : (($f['party']['passport'] ?? null) === $p->passport));
    $showFound = $found && ! $same($found, $owner) && ! ($vendor && $same($found, $vendor));
    $isVendor = $owner && $vendor && $owner->id === $vendor->id;
    $editOpen = $errors->any() && old('mode') === 'edit';
    $newOpen = $errors->any() && old('mode') === 'new';
@endphp
@if (! $can)
    <div class="row sale-row"><span class="sale-row-label">Собственник</span><span @class(['sale-row-value', 'text-danger' => ! $ready])>{{ $value }}</span><x-ui.chevron-space/></div>
@else
<div class="contents" data-controller="sheet" data-action="{{ $id }}:open@window->sheet#open">
    <x-deal.sale-row label="Собственник" :value="$owner && ! $ready ? $value.', нет данных' : $value" :tone="$ready ? null : 'danger'" data-action="sheet#open"/>
    <x-ui.sheet :id="$id" title="Собственник">
        <form method="post" action="{{ $action }}" class="flex flex-col gap-4" data-controller="autosubmit">
            @csrf @method('put')
            <div class="list">
                @if ($owner && ! $isVendor)
                    <x-people.option :picker="false" :person="['id' => 'edit', 'name' => SetDealOwner::label($owner), 'sub' => $sub($owner)]" name="mode" checked
                        action="change->autosubmit#submit" :edit-event="$id.'-edit:open'"/>
                @endif
                @if ($vendor)
                    <x-people.option :picker="false" :person="['id' => 'vendor', 'name' => $vendor->name, 'sub' => 'Сам поставщик, ИНН '.$vendor->inn]" name="mode" :checked="$isVendor"
                        action="change->autosubmit#submit"/>
                @endif
                @if ($showFound)
                    @php $fp = new \App\Billing\Party(['kind' => $found['kind']] + $found['party']); @endphp
                    <x-people.option :picker="false" :person="['id' => 'found', 'name' => SetDealOwner::label($fp), 'sub' => 'Из договора комиссии, '.mb_strtolower($sub($fp))]" name="mode"
                        action="change->autosubmit#submit"/>
                @endif
                <button type="button" class="row w-full text-left" data-controller="emit" data-action="sheet#close emit#send" data-emit-event-param="{{ $id }}-new:open">
                    <x-ui.row-icon name="plus" size="s" tone="accent"/>
                    <span class="min-w-0 flex-1 font-medium text-accent-text">Другой собственник</span>
                </button>
            </div>
            @error('owner')<p class="field-error">{{ $message }}</p>@enderror
        </form>
    </x-ui.sheet>
</div>
@foreach (array_filter(['edit' => $owner && ! $isVendor ? $owner : null, 'new' => true]) as $mode => $party)
    <div class="contents" data-controller="sheet" data-action="{{ $id }}-{{ $mode }}:open@window->sheet#open">
        <x-ui.sheet :id="$id.'-'.$mode" :title="$mode === 'edit' ? 'Данные собственника' : 'Другой собственник'" :open="$mode === 'edit' ? $editOpen : $newOpen" tall>
            <form method="post" action="{{ $action }}" class="flex flex-col gap-4">
                @csrf @method('put')
                <input type="hidden" name="mode" value="{{ $mode }}">
                <x-billing.buyer-fields :party="$mode === 'edit' ? $party : null" prefix="owner" :key="$id.'-'.$mode" owner/>
                <x-ui.sheet-foot>Сохранить</x-ui.sheet-foot>
            </form>
        </x-ui.sheet>
    </div>
@endforeach
@endif
