{{-- Деньги сделки одной формой: цена подтверждения, закупочная, разница, поле вознаграждения менеджера с живыми
     «Услугами подбора» (разница минус вознаграждение: наш счёт — за вычетом его, выплат менеджеру нет, 18.11.2026). Та же
     форма принимает подтверждение и правит сделку.
     С `scheme` — ещё кому платят за ТС (05.10.2026): ПРАЙМ по счёту, страхователю по ДКП или страховой напрямую. У двух
     последних — сколько собственнику или страховой (меньше закупочной — взаимозачёт), нам — подбор (цена минус эта сумма
     минус вознаграждение).
     07.10.2026: суммы строками (.fields), кнопка .sheet-foot (форма всегда в шторке). `fee` false — без вознаграждения
     (супер-покупатель покупает у нас сам, 10.10.2026): поле спрятано, услуги подбора — вся разница.
     15.10.2026: `cost` — закупочная, с которой менеджер подтверждал (закрепляется в сделке); `supplierCost` — нынешняя,
     если другая, строкой только для админа; у идущей сделки сумма собственнику не правится (`ownerLocked`).
     18.10.2026: `priced` — цену менеджеру вписывают тут же («Отдать» без подтверждения, поле `amount` — в слоте с
     `data-commission-target="price"`): разница и «нам остаётся» считаются по ходу набора, цена не выше закупочной —
     форма переспрашивает и шлёт согласие `loss`.
     28.10.2026: `costEditable` — закупочная менеджера полем (админ при принятии, владелец: «можешь поменять закупочную,
     которая будет у него в сделке»): по умолчанию с подтверждения, разница, взаимозачёт и согласие `loss` идут за ней.
     13.11.2026: `share` — доля менеджера от разницы (`users.deal_share`, владелец: «у Сахиба по умолчанию 50:50»);
     `prefill` (принятие) — пустое поле сразу долей, и поле идёт за разницей, пока его не правили руками.
     16.11.2026: вознаграждение — ползунком, суммой или процентом от разницы (`x-deal.reward-split`; чипа доли больше нет).
     25.11.2026: `rest`, `tone` — подпись остатка и тон полосы вознаграждения (`x-deal.reward-split`); слот `after` — под ней (шторка
     денег сделки ставит туда вторую полосу «на счёт | по ссылке» и наличные); `costFirst` — закупочная полем над слотом (над ценой
     продажи; владелец: «первым закупочная, вторым продажная»), `costLabel` — её подпись, `rubLabel` — подпись поля вознаграждения. --}}
@props(['action', 'amount', 'cost' => null, 'commission' => null, 'submit' => 'Принять', 'confirm' => null, 'method' => 'post', 'id' => null, 'note' => null,
    'scheme' => null, 'schemes' => null, 'ownerPrice' => null, 'fee' => true, 'ownerLocked' => false, 'supplierCost' => null, 'priced' => false, 'costEditable' => false, 'schemeLocked' => false, 'offer' => null, 'share' => null, 'prefill' => false, 'letter' => false, 'stored' => false, 'rest' => 'Подбор', 'tone' => null, 'costFirst' => false, 'costLabel' => 'Закупочная менеджера, ₽', 'rubLabel' => 'Менеджеру, ₽'])
@php
    use App\Offers\DealScheme;
    use App\Support\Money;
    $current = old('commission', $commission);
    $id ??= 'commission-'.uniqid();
    $scheme = DealScheme::tryFrom((string) old('scheme', $scheme?->value ?? '')) ?? $scheme;
    // ДКП и «страховой напрямую»: покупатель платит не нам — сумма ему, нам подбор, вознаграждение удерживает менеджер.
    $dkp = (bool) $scheme?->paysSelection();
    $payee = $scheme?->payeeLabel() ?? DealScheme::OwnerDkp->payeeLabel();
    $owner = old('owner_price', $ownerPrice ?? $cost);
    $costNow = $costEditable ? (Money::rubles(old('cost')) ?: $cost) : $cost;
    $margin = $costNow === null ? null : (int) $amount - $costNow;
    $share = $fee ? $share : null;
    if ($prefill && $share !== null && ($current === null || $current === '') && $margin !== null && ($amount || ! $priced)) {
        $current = \App\Offers\Deal::shareOf($margin, $share);
    }
@endphp
<form method="post" action="{{ $action }}" class="flex flex-col gap-4" data-scheme-guard data-controller="commission" data-commission-amount-value="{{ $amount }}" @if ($costNow !== null) data-commission-cost-value="{{ $costNow }}" @endif
    @if ($margin !== null) data-commission-margin-value="{{ $margin }}" @endif @if ($confirm) data-turbo-confirm="{{ $confirm }}" @endif>
    @csrf
    @if ($method !== 'post')@method($method)@endif
    {{-- Что стояло, когда форму нарисовали (22.11.2026, 2611123939: «Цена» гаражной посчитала долю, а вторая отправка той же формы с
         пустым полем стёрла её в ноль): пустое поле при пустом «было» не стирает появившееся с тех пор (`DealController::money`). --}}
    <input type="hidden" name="commission_was" value="{{ ($stored === false ? $commission : $stored) ?? '' }}">
    @if ($priced || $costEditable)<input type="hidden" name="loss" value="" data-commission-target="loss">@endif
    @if ($costEditable && $costFirst)
        <div class="field @error('cost') field-invalid @enderror">
            <label for="{{ $id }}-cost" class="field-label">{{ $costLabel }}</label>
            <input type="hidden" name="cost" data-commission-target="costAmount" value="{{ $costNow }}">
            <input id="{{ $id }}-cost" type="text" inputmode="decimal" class="field-input nums" data-controller="digits" data-digits-decimals-value="0" data-commission-target="costDisplay" data-action="input->digits#format input->commission#input" value="{{ $costNow ? Money::nums((int) $costNow) : '' }}" autocomplete="off">
            @error('cost')<p class="field-error">{{ $message }}</p>@enderror
        </div>
    @endif
    {{ $slot }}
    {{-- Схема сделки меняется своей строкой «Схема» (`x-deal.scheme`, 03.11.2026): в «Деньгах» — только она сама, без выбора. --}}
    @if ($scheme && $schemeLocked)
        <input type="hidden" name="scheme" value="{{ $scheme->value }}">
    @elseif ($scheme)
        {{-- Схема — строками с фразой, кто кому платит, умолчание первым и отмечено (03.11.2026, владелец: чипы «ПРАЙМ по счёту /
             Страховой напрямую» путали — Land Cruiser 781R приняли через ПРАЙМ, а договор напрямую). Не умолчание — «Точно?»
             (`data-ask`, `scheme-guard` в `price-guard.js`; сервер — `DealScheme::unusual` и `scheme_ok`). --}}
        <div class="flex flex-col gap-1.5">
            <span class="field-label">Договор и оплата за ТС</span>
            @if ($errors->has('scheme_check'))<input type="hidden" name="scheme_ok" value="1">@endif
            <div class="list">
                @foreach ($schemes ?? DealScheme::casesFor(null) as $s)
                    <label class="row row-check">
                        <span class="min-w-0 flex-1"><span class="block">{{ $s->label() }}</span><span class="row-sub">{{ $s->hint() }}</span></span>
                        <span class="check"><input type="radio" name="scheme" value="{{ $s->value }}" @checked($scheme === $s) data-action="commission#scheme" data-selection="{{ $s->paysSelection() ? 1 : 0 }}" data-payee="{{ $s->payeeLabel() }}" @if ($offer && ($ask = DealScheme::unusual($offer, $s))) data-ask="{{ $ask }}" @endif></span>
                    </label>
                @endforeach
            </div>
            @error('scheme')<p class="field-error">{{ $message }}</p>@enderror
        </div>
    @endif
    <dl class="grid grid-cols-[auto_1fr] items-baseline gap-x-4 gap-y-1.5">
        @unless ($priced)<dt class="text-sm text-ink-dim">Цена подтверждения</dt><dd class="nums text-right font-medium">{{ Money::rub($amount) }}</dd>@endunless
        @unless ($costEditable)<dt class="text-sm text-ink-dim">Закупочная</dt><dd class="nums text-right">{{ $cost === null ? 'не указана' : Money::rub($cost) }}</dd>@endunless
        @if ($supplierCost !== null && $supplierCost !== $costNow)<dt class="text-sm text-ink-dim">Закупочная поставщика</dt><dd class="nums text-right">{{ Money::rub($supplierCost) }}</dd>@endif
        @if ($margin !== null || $costEditable)<dt class="text-sm text-ink-dim" data-commission-target="oursOnly" @if ($dkp) hidden @endif>Разница</dt><dd class="nums text-right font-semibold {{ $margin < 0 ? 'text-danger' : '' }}" data-commission-target="oursOnly margin" @if ($dkp) hidden @endif>{{ $margin === null || ($priced && ! $amount) ? '' : Money::rub($margin) }}</dd>@endif
    </dl>
    <div class="flex flex-col gap-3">
        @if ($costEditable && ! $costFirst)
            <div class="field @error('cost') field-invalid @enderror">
                <label for="{{ $id }}-cost" class="field-label">{{ $costLabel }}</label>
                <input type="hidden" name="cost" data-commission-target="costAmount" value="{{ $costNow }}">
                <input id="{{ $id }}-cost" type="text" inputmode="decimal" class="field-input nums" data-commission-target="costDisplay" data-action="input->commission#input" value="{{ $costNow ? Money::nums((int) $costNow) : '' }}" autocomplete="off">
                @error('cost')<p class="field-error">{{ $message }}</p>@enderror
            </div>
        @endif
        @if ($scheme)
            <div class="field @error('owner_price') field-invalid @enderror" data-commission-target="dkpOnly" @unless ($dkp) hidden @endunless>
                <label for="{{ $id }}-owner" class="field-label"><span data-commission-target="payee">{{ $payee }}</span>, ₽</label>
                <input type="hidden" name="owner_price" data-commission-target="ownerAmount" value="{{ $owner }}">
                <input id="{{ $id }}-owner" type="text" class="field-input nums" data-commission-target="ownerDisplay" data-action="input->commission#input" value="{{ $owner ? Money::nums((int) $owner) : '' }}" autocomplete="off" @if ($ownerLocked) readonly @endif>
                <p class="col-span-full pb-2 text-right text-sm text-ink-muted empty:hidden" data-commission-target="offset"></p>
                @error('owner_price')<p class="field-error">{{ $message }}</p>@enderror
            </div>
        @endif
        {{-- Вознаграждение ползунком, суммой или процентом от разницы (`x-deal.reward-split`, 16.11.2026); доля менеджера
             `users.deal_share` — предзаполнением, поле идёт за разницей процентом, пока сумму не вписали руками. --}}
        <div @unless ($fee) hidden @endunless>
            <x-deal.reward-split name="commission" :id="$id" :value="$current === null || $current === '' ? null : (int) $current" :margin="$margin !== null && ($amount || ! $priced) ? $margin : null"
                :follow="$prefill && $share !== null && $current !== null && $margin !== null && (int) $current === \App\Offers\Deal::shareOf($margin, $share)" :share="$prefill ? $share : null"
                :show-margin="false" :hidden="['data-commission-target' => 'amount']" :rest="$rest" :tone="$tone" :rub-label="$rubLabel" data-commission-target="display" data-action="input->commission#input"/>
        </div>
        {{ $after ?? '' }}
    </div>
    @if ($margin !== null || $scheme)
        {{-- Услуги подбора у ПРАЙМ — уже подписью на полосе вознаграждения (16.11.2026): строкой — только у ДКП и «страховой
             напрямую» (не из разницы) и без вознаграждения (супер-покупатель). --}}
        <dl class="grid grid-cols-[auto_1fr] items-baseline gap-x-4" @if ($fee) data-commission-target="dkpOnly" @unless ($dkp) hidden @endunless @endif>
            <dt class="text-sm text-ink-dim">Услуги подбора</dt>
            <dd class="nums text-right text-lg font-semibold" data-commission-target="ours">{{ $margin !== null && ($amount || ! $priced) ? Money::rub($margin - (int) $current) : '' }}</dd>
        </dl>
    @endif
    @if ($note ?? null)<p class="text-sm text-ink-muted">{{ $note }}</p>@endif
    {{-- Над кнопкой — «Не снимать с продажи» у «Принять» / «Отдать» (`x-offer.keep-selling`). --}}
    {{ $keep ?? '' }}
    {{-- Принятие, после которого маршрут попросит письмо вендору, — выбор «и написать письмо» / «без письма» (`x-offer.give-foot`). --}}
    @if ($letter && $offer)<x-offer.give-foot :offer="$offer" :label="$submit"/>@else<x-ui.sheet-foot>{{ $submit }}</x-ui.sheet-foot>@endif
</form>
