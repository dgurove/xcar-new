{{-- Подтверждение менеджера — одно на редактор и карточка строки: сумма, насколько она от цены продажи, «Лучшая» и «ниже
     минимальной» (строкой группы .list в bids.blade), менеджер с телефоном, когда, комментарий. У ждущего — «Принять» (у предложения в сделке — «Отдать»:
     прежняя сделка отменится) шторкой с деньгами сделки и «Отклонить» рядом — одинаково в редакторе и карточке строки.
     Гаражное («В гараж») — без цены: сколько машин у менеджера уже в гараже, в шторке — кто платит поставщику. --}}
@props(['bid', 'offer', 'best' => false, 'parked' => collect(), 'branch' => false])
@php
    use App\Offers\BidState;
    use App\Support\Money;
    $active = $bid->state === BidState::Active;
    $garage = $bid->isGarage();
    $diff = $offer->asking_price && ! $garage ? $bid->amount - $offer->asking_price : null;
    $low = $active && ! $garage && ($min = $offer->minBid()) && $bid->amount < $min;
    $give = (bool) $offer->deal;
    $inGarage = $garage ? (int) ($parked[$bid->user_id] ?? 0) : 0;
@endphp
<div class="px-4 py-3">
    <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
        <span class="nums whitespace-nowrap text-lg font-semibold">{{ $garage ? 'В гараж' : Money::rub($bid->amount) }}</span>
        @if ($inGarage)<span class="tag nums">в гараже {{ $inGarage }}</span>@endif
        @if ($diff)<span class="nums whitespace-nowrap text-sm text-ink-dim">{{ $diff > 0 ? '+' : '−' }}{{ Money::nums(abs($diff)) }} от цены</span>@endif
        @if ($best)<x-ui.state tone="soft">Лучшая</x-ui.state>@endif
        @if ($low)<span class="tag tag-urgent">ниже минимальной</span>@endif
        @if (! $active)<x-ui.state :tone="$bid->state === BidState::Accepted ? 'open' : ($bid->state === BidState::Declined ? 'danger' : 'closed')">{{ $bid->state->label() }}</x-ui.state>@endif
    </div>
    <div class="mt-1 flex flex-wrap items-center gap-1.5"><x-ui.person :user="$bid->user" full/><a href="tel:+{{ $bid->user->phone }}" class="tag nums">{{ $bid->user->phoneFormatted() }}</a><span class="tag nums">{{ $bid->created_at->translatedFormat('j M, H:i') }}</span>@if ($bid->byStaff())<span class="text-sm text-ink-dim">внёс {{ $bid->placedBy?->shortName() }}</span>@endif</div>
    @if ($bid->comment)<div class="mt-1 text-sm">{{ $bid->comment }}</div>@endif
    @if ($active)
        <div class="mt-2 flex items-start gap-2">
            <div data-controller="sheet">
                <x-ui.button type="button" size="sm" data-action="sheet#open">{{ $give ? 'Отдать' : 'Принять' }}</x-ui.button>
                <x-ui.sheet id="accept-{{ $bid->id }}" :title="$give ? 'Отдать другому' : ($garage ? 'Отдать в гараж' : 'Принять подтверждение')" :open="($errors->has('commission') || $errors->has('payer')) && old('bid') == $bid->id">
                    <div class="mb-4 flex flex-wrap items-center gap-1.5"><x-ui.person :user="$bid->user" full/><span class="tag">{{ $offer->titleWithYear() }}</span></div>
                    @if ($garage)
                        {{-- Вознаграждения тут нет: его назначают, когда менеджер продаст машину из гаража. Без гаражной
                             ветки у маршрута (Т-Страхование) поставщику платит менеджер — выбора нет. --}}
                        <form method="post" action="/confirmations/{{ $bid->id }}/accept" class="flex flex-col gap-4">
                            @csrf<input type="hidden" name="bid" value="{{ $bid->id }}">
                            @if ($branch)
                                <div class="flex flex-col gap-1.5">
                                    <span class="field-label">Платит поставщику</span>
                                    <div class="flex flex-wrap gap-2">
                                        @foreach (\App\Garage\GaragePayer::cases() as $payer)
                                            <label class="choice"><input type="radio" name="payer" value="{{ $payer->value }}" @checked(old('payer', 'us') === $payer->value)><span>{{ $payer->label() }}</span></label>
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                            {{-- Везут к нему в гараж: сам или мы (05.10.2026); выбрано, кто уже вывозит. --}}
                            @if ($offer->pickupChoosable())
                                @php $byBuyer = ! $offer->evacuator_id || $offer->evacuator_id === $bid->user_id; @endphp
                                <div class="flex flex-col gap-1.5">
                                    <span class="field-label">Везёт в гараж</span>
                                    <div class="flex flex-wrap gap-2">
                                        <label class="choice"><input type="radio" name="pickup" value="manager" @checked(old('pickup', $byBuyer ? 'manager' : 'us') === 'manager')><span>{{ $bid->user->shortName() }}</span></label>
                                        <label class="choice"><input type="radio" name="pickup" value="us" @checked(old('pickup', $byBuyer ? 'manager' : 'us') === 'us')><span>Мы</span></label>
                                    </div>
                                </div>
                            @endif
                            @if ($give)<p class="text-sm text-ink-muted">Сделка с {{ $offer->deal->buyer?->shortName() }} отменится</p>@endif
                            <x-ui.button type="submit" variant="primary" block>{{ $give ? 'Отдать' : 'В гараж' }}</x-ui.button>
                        </form>
                    @else
                        <x-offer.money-form :action="'/confirmations/'.$bid->id.'/accept'" :amount="$bid->amount" :cost="$offer->floor_price" :scheme="$offer->vendor?->deal_format === \App\Vendors\DealFormat::Direct ? \App\Offers\DealScheme::OwnerDkp : \App\Offers\DealScheme::Ours" :owner-price="$offer->owner_price" :submit="$give ? 'Отдать' : 'Принять'" :note="$give ? 'Сделка с '.$offer->deal->buyer?->shortName().' отменится' : null">
                            <input type="hidden" name="bid" value="{{ $bid->id }}">
                            {{-- ТС ещё у владельца — кто её забирает (04.10.2026): менеджер сделки по умолчанию; поручено
                                 другому — по умолчанию «Мы», то есть как назначено. --}}
                            @if ($offer->pickupChoosable())
                                @php $byBuyer = ! $offer->evacuator_id || $offer->evacuator_id === $bid->user_id; @endphp
                                <div class="flex flex-col gap-1.5">
                                    <span class="field-label">Забирает автомобиль</span>
                                    <div class="flex flex-wrap gap-2">
                                        <label class="choice"><input type="radio" name="pickup" value="manager" @checked(old('pickup', $byBuyer ? 'manager' : 'us') === 'manager')><span>{{ $bid->user->shortName() }}</span></label>
                                        <label class="choice"><input type="radio" name="pickup" value="us" @checked(old('pickup', $byBuyer ? 'manager' : 'us') === 'us')><span>Мы</span></label>
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
