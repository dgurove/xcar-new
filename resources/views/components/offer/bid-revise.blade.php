{{-- «Изменить подтверждение» (22.11.2026, владелец: «подтверждение в сделку сделать подтверждением в гараж и наоборот»): одна
     шторка у админа (CRM, пункт «Изменить…» в шторке подтверждения) и у менеджера (сайт, «Изменить» у своего, пока ждёт
     решения). Сегмент «Для покупателя | В гараж» (стоит текущий, `reveal`): у покупателя — цена продажи строкой, цена крупно,
     чипы цены продажи и скидок, у админа при «вдвое выше» — «Без лишнего нуля» и «Как было»; у гаража — «без цены, по
     закупочной» и сколько у менеджера уже в гараже. Админу гараж можно и без галки «Можно в гараж» у ТС — строкой
     предупреждения; менеджеру сегмента нет, где ему в гараж нельзя. Менеджеру — «Вы уверены?» и цена зонами (`ReviseBid`:
     ниже порога и «вдвое выше» — ошибкой с `low`, вторая отправка с согласием `low_ok`). Открывается событием `event`. --}}
@props(['bid', 'offer', 'event', 'staff' => false, 'inGarage' => 0])
@php
    use App\Support\Money;
    $garage = $bid->isGarage();
    $kind = old('kind', $garage ? 'garage' : 'buyer');
    $asking = (int) $offer->asking_price;
    $amount = (int) $bid->amount;
    // Опечатка «лишний ноль» (02.11.2026) — подсказка и сразу в поле у админа.
    $tenth = $staff && ! $garage && $amount && $offer->bidZone($amount) === 'high' ? (int) round($amount / 10, -3) : 0;
    $value = old('amount', ($tenth ?: $amount) ? Money::nums($tenth ?: $amount) : '');
    $allowed = $offer->garageAllowedFor($bid->user);
    $canGarage = $staff || $allowed;
    $discounts = $asking ? $offer->bidDiscounts($bid->user) : [];
    $who = $bid->user->shortName();
    $action = '/confirmations/'.$bid->id.'/revise';
@endphp
<span class="contents" data-controller="sheet" data-action="{{ $event }}:open@window->sheet#open">
    <x-ui.sheet :id="'bid-revise-'.$bid->id" title="Изменить подтверждение">
        <form method="post" action="{{ $action }}" class="flex flex-col gap-4" data-controller="reveal" data-put-scope>
            @csrf
            <input type="hidden" name="low_ok" value="{{ $errors->has('low') ? '1' : '' }}">
            <p class="text-sm text-ink-muted">{{ $staff ? $who.', было' : 'Было' }}: <span class="nums text-ink">{{ $garage ? 'в гараж' : Money::rub($amount) }}</span></p>
            @if ($canGarage)
                <div class="segment">
                    <label><input type="radio" name="kind" value="buyer" data-action="reveal#pick" @checked($kind === 'buyer')>Для покупателя</label>
                    <label><input type="radio" name="kind" value="garage" data-action="reveal#pick" @checked($kind === 'garage')>В гараж</label>
                </div>
            @else
                <input type="hidden" name="kind" value="buyer">
            @endif
            <div class="flex flex-col gap-4" data-reveal-target="pane" data-reveal-key="buyer" @if ($kind !== 'buyer') hidden @endif>
                @if ($asking)<p class="text-center text-sm text-ink-muted">Цена продажи <span class="nums">{{ Money::rub($asking) }}</span></p>@endif
                <label class="flex flex-col items-center gap-1">
                    <span class="flex items-baseline justify-center gap-2">
                        <input name="amount" inputmode="decimal" autocomplete="off" class="pay-amount nums" aria-label="Цена, ₽" placeholder="0"
                               value="{{ $value }}" data-controller="digits" data-action="input->digits#format">
                        <span class="text-xl text-ink-muted">₽</span>
                    </span>
                    @error('amount')<span class="text-center text-sm {{ $errors->has('low') ? 'text-urgent' : 'text-danger' }}">{{ $message }}</span>@enderror
                </label>
                @if ($tenth || $asking)
                    <div class="sheet-chips justify-center">
                        @if ($tenth)
                            <button type="button" class="chip nums" data-controller="put" data-action="put#set" data-put-value-param="{{ Money::nums($tenth) }}" data-put-keep-param="true">Без лишнего нуля {{ Money::nums($tenth) }}</button>
                            <button type="button" class="chip nums" data-controller="put" data-action="put#set" data-put-value-param="{{ Money::nums($amount) }}" data-put-keep-param="true">Как было {{ Money::nums($amount) }}</button>
                        @else
                            <button type="button" class="chip nums" data-controller="put" data-action="put#set" data-put-value-param="{{ Money::nums($asking) }}" data-put-keep-param="true">{{ Money::nums($asking) }}</button>
                            @foreach ($discounts as $percent => $price)
                                <button type="button" class="chip nums" data-controller="put" data-action="put#set" data-put-value-param="{{ Money::nums($price) }}" data-put-keep-param="true">−{{ $percent }}%</button>
                            @endforeach
                        @endif
                    </div>
                @endif
                <div class="sheet-foot"><button type="submit" class="btn btn-accent btn-block" @unless ($staff) {{ \App\Offers\Warnings::html('Подтверждение станет для покупателя по новой цене', 'Да, для покупателя') }} @endunless>Сохранить</button></div>
            </div>
            @if ($canGarage)
                <div class="flex flex-col gap-4" data-reveal-target="pane" data-reveal-key="garage" @if ($kind !== 'garage') hidden @endif>
                    <p class="text-center text-sm text-ink-muted">Без цены, по закупочной@if ($staff && $inGarage). У менеджера в гараже <span class="nums">{{ $inGarage }}</span> ТС@endif</p>
                    @if ($staff && ! $allowed)<p class="text-center text-sm text-urgent">У ТС не стоит «Можно в гараж»</p>@endif
                    <div class="sheet-foot"><button type="submit" class="btn btn-accent btn-block" @unless ($staff) {{ \App\Offers\Warnings::html('Подтверждение станет «В гараж на подготовку», без цены', 'Да, в гараж') }} @endunless>Сохранить: в гараж</button></div>
                </div>
            @endif
            @if ($staff)<p class="text-center text-xs text-ink-muted">Менеджеру придёт уведомление</p>@endif
        </form>
    </x-ui.sheet>
</span>
