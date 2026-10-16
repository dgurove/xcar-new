{{-- Подтверждение менеджера — одно на редактор и карточка строки: сумма, насколько она от цены продажи, «Лучшая» и «ниже
     минимальной» (строкой группы .list в bids.blade), менеджер с телефоном, когда, комментарий. У ждущего — «Принять» (у предложения в сделке — «Отдать»:
     прежняя сделка отменится) шторкой с деньгами сделки и «Отклонить» рядом — одинаково в редакторе и карточке строки.
     Гаражное («В гараж») — без цены: сколько машин у менеджера уже в гараже, в шторке — кто платит поставщику. 07.10.2026: доля строкой (.fields), кнопка .sheet-foot. --}}
@props(['bid', 'offer', 'best' => false, 'parked' => collect(), 'branch' => false])
@php
    use App\Offers\BidState;
    use App\Support\Money;
    $active = $bid->state === BidState::Active;
    $garage = $bid->isGarage();
    $diff = $offer->asking_price && ! $garage ? $bid->amount - $offer->asking_price : null;
    $low = $active && ! $garage && ($min = $offer->minBid()) && $bid->amount < $min;
    // Не выше закупочной (14.10.2026, 3308 приняли в минус молча): красным в строке, «Принять» переспрашивает, и сервер
    // без согласия такую не примет (`AcceptBid`, `loss`).
    // Закупочная, с которой менеджер подтверждал (`bids.cost`, 15.10.2026), — она закрепится в сделке.
    $floor = $bid->frozenCost();
    $under = ! $garage && $floor && $bid->amount !== null ? $floor - $bid->amount : null;
    $loss = $under !== null && $under >= 0;
    $give = (bool) $offer->deal;
    $inGarage = $garage ? (int) ($parked[$bid->user_id] ?? 0) : 0;
@endphp
<div class="px-4 py-3">
    <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
        <span class="nums whitespace-nowrap text-lg font-semibold">{{ $garage ? 'В гараж' : Money::rub($bid->amount) }}</span>
        @if ($inGarage)<span class="nums text-sm text-ink-dim">в гараже {{ $inGarage }}</span>@endif
        @if ($diff)<span class="nums whitespace-nowrap text-sm text-ink-dim">{{ $diff > 0 ? '+' : '−' }}{{ Money::nums(abs($diff)) }} от цены</span>@endif
        @if ($best)<x-ui.state tone="soft">Лучшая</x-ui.state>@endif
        @if ($loss && $active)<x-ui.state tone="danger">{{ $under > 0 ? 'ниже закупочной на '.Money::nums($under) : 'равна закупочной' }}</x-ui.state>@elseif ($low)<x-ui.state tone="urgent">ниже минимальной</x-ui.state>@endif
        @if (! $active)<x-ui.state :tone="$bid->state === BidState::Accepted ? 'open' : ($bid->state === BidState::Declined ? 'danger' : 'closed')">{{ $bid->state->label() }}</x-ui.state>@endif
    </div>
    {{-- Кто — человеком, телефон ссылкой и когда — текстом (10.10.2026: «что-то чипами, что-то текстом»). --}}
    <div class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm"><x-ui.person :user="$bid->user" full/>@if ($bid->user->isSuperBuyer())<span class="text-ink-dim">супер-покупатель</span>@endif @if ($bid->user->phone)<x-ui.phone :user="$bid->user"/>@endif<span class="nums text-ink-dim">{{ $bid->created_at->translatedFormat('j M, H:i') }}</span>@if ($bid->byStaff())<span class="text-ink-dim">внёс {{ $bid->placedBy?->shortName() }}</span>@endif</div>
    @if ($bid->comment)<div class="mt-1 text-sm">{{ $bid->comment }}</div>@endif
    @if ($active)
        <div class="mt-2 flex items-start gap-2">
            <div data-controller="sheet">
                <x-ui.button type="button" size="sm" data-action="sheet#open">{{ $give ? 'Отдать' : 'Принять' }}</x-ui.button>
                <x-ui.sheet id="accept-{{ $bid->id }}" :title="$give ? 'Отдать другому' : ($garage ? 'Отдать в гараж' : 'Принять подтверждение')" :open="$errors->hasAny(['commission', 'payer', 'share', 'owner_price', 'bid']) && old('bid') == $bid->id">
                    <div class="mb-4 flex flex-wrap items-center gap-1.5"><x-ui.person :user="$bid->user" full/><span class="tag">{{ $offer->titleWithYear() }}</span></div>
                    @if ($errors->has('bid') && old('bid') == $bid->id)<x-ui.flash tone="danger" class="mb-4">{{ $errors->first('bid') }}</x-ui.flash>@endif
                    @if ($garage)
                        {{-- Вознаграждения тут нет: его назначают, когда менеджер продаст машину из гаража. У Совкомбанка
                             (менеджер платит ПРАЙМ по счёту) и без гаражной ветки маршрута (Т-Страхование) платит менеджер —
                             выбора нет (`Offer::garagePayerFixed`). --}}
                        <form method="post" action="/confirmations/{{ $bid->id }}/accept" class="flex flex-col gap-4" data-controller="reveal">
                            @csrf<input type="hidden" name="bid" value="{{ $bid->id }}">
                            @if ($branch)
                                <div class="flex flex-col gap-1.5">
                                    <span class="field-label">Платит поставщику</span>
                                    <div class="flex flex-wrap gap-2">
                                        @foreach (\App\Garage\GaragePayer::cases() as $payer)
                                            <label class="choice"><input type="radio" name="payer" value="{{ $payer->value }}" @checked(old('payer', 'us') === $payer->value) data-action="reveal#pick"><span>{{ $payer->label() }}</span></label>
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                            {{-- Платит поставщику менеджер — нашу долю он платит по ссылке сразу (05.10.2026). --}}
                            {{-- У Совкомбанка доли нет: машина уходит без оплаты, нашу часть даёт продажа (`Deal::namesBuyer`). --}}
                            @unless ($offer->vendor?->isCommission())
                            <div class="fields" data-reveal-target="pane" data-reveal-key="manager" @if ($branch && old('payer', 'us') !== 'manager') hidden @endif>
                                <x-ui.field name="share" label="Наша доля, ₽" :value="old('share')"/>
                            </div>
                            @endunless
                            {{-- Везут к нему в гараж: сам или мы (05.10.2026); выбрано, кто уже вывозит. Вывоз у гаражной
                                 есть всегда (06.10.2026), даже у вендора без маршрута — заведётся сам. --}}
                            @if (! $offer->pickedUp() && $offer->parkVehicle?->state !== \App\Park\VehicleState::Stored)
                                @php $byBuyer = ! $offer->evacuator_id || $offer->evacuator_id === $bid->user_id; @endphp
                                <div class="flex flex-col gap-1.5">
                                    <span class="field-label">Везёт в гараж</span>
                                    <div class="flex flex-wrap gap-2">
                                        <label class="choice"><input type="radio" name="pickup" value="manager" @checked(old('pickup', $byBuyer ? 'manager' : 'us') === 'manager')><span>{{ $bid->user->shortName() }}</span></label>
                                        <label class="choice"><input type="radio" name="pickup" value="us" @checked(old('pickup', $byBuyer ? 'manager' : 'us') === 'us')><span>Логистика</span></label>
                                    </div>
                                </div>
                            @endif
                            @if ($give)<p class="text-sm text-ink-muted">Сделка с {{ $offer->deal->buyer?->shortName() }} отменится</p>@endif
                            <x-ui.sheet-foot type="submit" variant="primary">{{ $give ? 'Отдать' : 'В гараж' }}</x-ui.sheet-foot>
                        </form>
                    @else
                        <x-offer.money-form :action="'/confirmations/'.$bid->id.'/accept'" :amount="$bid->amount" :cost="$floor" :supplier-cost="$offer->floor_price" :scheme="\App\Offers\DealScheme::choiceFor($offer->vendor?->deal_format, \App\Offers\DealScheme::forOffer($offer))" :schemes="\App\Offers\DealScheme::casesFor($offer->vendor)" :owner-price="$offer->owner_price" :fee="! $bid->user->isSuperBuyer()" :submit="$give ? 'Отдать' : 'Принять'" :note="$give ? 'Сделка с '.$offer->deal->buyer?->shortName().' отменится' : null"
                            :confirm="$loss ? 'Принять за '.Money::rub($bid->amount).' при закупочной '.Money::rub($floor).'? '.($under > 0 ? 'Сделка в минус '.Money::rub($under) : 'Наценки нет') : null">
                            <input type="hidden" name="bid" value="{{ $bid->id }}">
                            @if ($loss)<input type="hidden" name="loss" value="1">@endif
                            {{-- ТС ещё у владельца — кто её забирает (04.10.2026): менеджер сделки по умолчанию; поручено
                                 другому — по умолчанию «Мы», то есть как назначено. --}}
                            {{-- Совкомбанк: машину забирает сам менеджер, его шаг «Заберите автомобиль» (10.10.2026) — выбора нет. --}}
                            @if ($offer->pickupChoosable() && $offer->vendor?->isCommission())
                                <input type="hidden" name="pickup" value="manager">
                            @elseif ($offer->pickupChoosable())
                                @php $byBuyer = ! $offer->evacuator_id || $offer->evacuator_id === $bid->user_id; @endphp
                                <div class="flex flex-col gap-1.5">
                                    <span class="field-label">Забирает автомобиль</span>
                                    <div class="flex flex-wrap gap-2">
                                        <label class="choice"><input type="radio" name="pickup" value="manager" @checked(old('pickup', $byBuyer ? 'manager' : 'us') === 'manager')><span>{{ $bid->user->shortName() }}</span></label>
                                        <label class="choice"><input type="radio" name="pickup" value="us" @checked(old('pickup', $byBuyer ? 'manager' : 'us') === 'us')><span>Логистика</span></label>
                                    </div>
                                </div>
                            @endif
                        </x-offer.money-form>
                    @endif
                </x-ui.sheet>
            </div>
            <form method="post" action="/confirmations/{{ $bid->id }}/decline" data-turbo-confirm="Отклонить подтверждение {{ $bid->user->shortName() }}?">@csrf<x-ui.button size="sm" variant="ghost">Отклонить</x-ui.button></form>
        </div>
    @endif
</div>
