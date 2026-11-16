{{-- «Кому — ООО «ПРАЙМ» ›» (16.11.2026, владелец: «не „Плательщик платит“, а „Кому“») — кому платят услуги подбора (16.11.2026, владелец: «по аналогии с „Покупатель платит“ —
     выбор ооо прайм или ооо р.о.с.»). Строкой блока «Услуги подбора» «Продажи»; шторка — два варианта, выбор сразу (`SetFeeSeller`:
     счёт подбора перевыставляется от выбранного). По счёту платили или не админ — строкой без выбора. --}}
@props(['deal'])
@php
    use App\Offers\Actions\SetFeeSeller;
    $current = $deal->feeSeller();
    $name = $current->config()['name'] ?? $current->value;
    $editable = auth()->user()?->canManageCrm() && $deal->isActive() && ! SetFeeSeller::locked($deal);
    $id = 'fee-seller-'.$deal->id;
@endphp
@if ($editable)
    <div class="contents" data-controller="sheet">
        <x-deal.sale-row label="Кому" :value="$name" data-action="sheet#open"/>
        <x-ui.sheet :id="$id" title="Кому платят услуги подбора">
            <form method="post" action="/work/deals/{{ $deal->id }}/fee-seller" data-controller="autosubmit">
                @csrf @method('put')
                <div class="list">
                    @foreach (SetFeeSeller::SELLERS as $s)
                        <label class="row row-check">
                            <span class="min-w-0 flex-1">{{ $s->config()['name'] ?? $s->value }}@if ($s === \App\Billing\Seller::Ros)<span class="row-sub">без ссылки на оплату — только по счёту</span>@endif</span>
                            <span class="check"><input type="radio" name="fee_seller" value="{{ $s->value }}" @checked($s === $current) data-action="change->autosubmit#submit"></span>
                        </label>
                    @endforeach
                </div>
                @error('fee_seller')<p class="field-error mt-2">{{ $message }}</p>@enderror
            </form>
        </x-ui.sheet>
    </div>
@else
    <div class="row sale-row"><span class="sale-row-label">Кому</span><span class="sale-row-value">{{ $name }}</span><x-ui.chevron-space/></div>
@endif
