{{-- Подтверждение менеджера — одно на редактор и окошко строки: сумма, насколько она от цены продажи, «Лучшая» и «ниже
     минимальной», менеджер с телефоном, когда, комментарий. У ждущего — «Принять» (у предложения в сделке — «Отдать»:
     прежняя сделка отменится) и «Отклонить». inline — окошко строки: форма денег раскрывается на месте, а не шторкой. --}}
@props(['bid', 'offer', 'best' => false, 'inline' => false])
@php
    use App\Offers\BidState;
    use App\Support\Money;
    $active = $bid->state === BidState::Active;
    $diff = $offer->asking_price ? $bid->amount - $offer->asking_price : null;
    $low = $active && ($min = $offer->minBid()) && $bid->amount < $min;
    $give = (bool) $offer->deal;
@endphp
<div class="box-nested">
    <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
        <span class="nums whitespace-nowrap text-lg font-semibold">{{ Money::rub($bid->amount) }}</span>
        @if ($diff)<span class="nums whitespace-nowrap text-sm text-ink-dim">{{ $diff > 0 ? '+' : '−' }}{{ Money::nums(abs($diff)) }} от цены</span>@endif
        @if ($best)<x-ui.pill tone="soft" class="!min-h-0 !py-1 text-xs">Лучшая</x-ui.pill>@endif
        @if ($low)<span class="tag tag-urgent">ниже минимальной</span>@endif
        @if (! $active)<x-ui.pill :tone="$bid->state === BidState::Accepted ? 'open' : ($bid->state === BidState::Declined ? 'danger' : 'closed')" class="!min-h-0 !py-1 text-xs">{{ $bid->state->label() }}</x-ui.pill>@endif
    </div>
    <div class="mt-1 flex flex-wrap items-center gap-1.5"><x-ui.person :user="$bid->user" full/><a href="tel:+{{ $bid->user->phone }}" class="tag nums">{{ $bid->user->phoneFormatted() }}</a><span class="tag nums">{{ $bid->created_at->translatedFormat('j M, H:i') }}</span></div>
    @if ($bid->comment)<div class="mt-1 text-sm">{{ $bid->comment }}</div>@endif
    @if ($active)
        <div class="mt-2 flex items-start gap-2">
            @if ($inline)
                <details class="min-w-0 flex-1" @if ($errors->has('commission') && old('bid') == $bid->id) open @endif>
                    <summary class="btn btn-s btn-accent inline-flex cursor-pointer">{{ $give ? 'Отдать' : 'Принять' }}</summary>
                    <div class="mt-3"><x-offer.money-form :action="'/confirmations/'.$bid->id.'/accept'" :amount="$bid->amount" :cost="$offer->floor_price" :submit="$give ? 'Отдать' : 'Принять'" :note="$give ? 'Сделка с '.$offer->deal->buyer?->shortName().' отменится' : null"><input type="hidden" name="bid" value="{{ $bid->id }}"></x-offer.money-form></div>
                </details>
            @else
                <div data-controller="sheet">
                    <x-ui.button type="button" size="sm" data-action="sheet#open">{{ $give ? 'Отдать' : 'Принять' }}</x-ui.button>
                    <x-ui.sheet id="accept-{{ $bid->id }}" :title="$give ? 'Отдать другому' : 'Принять подтверждение'" :open="$errors->has('commission') && old('bid') == $bid->id">
                        <div class="mb-4 flex flex-wrap items-center gap-1.5"><x-ui.person :user="$bid->user" full/><span class="tag">{{ $offer->titleWithYear() }}</span></div>
                        <x-offer.money-form :action="'/confirmations/'.$bid->id.'/accept'" :amount="$bid->amount" :cost="$offer->floor_price" :submit="$give ? 'Отдать' : 'Принять'" :note="$give ? 'Сделка с '.$offer->deal->buyer?->shortName().' отменится' : null"><input type="hidden" name="bid" value="{{ $bid->id }}"></x-offer.money-form>
                    </x-ui.sheet>
                </div>
            @endif
            <form method="post" action="/confirmations/{{ $bid->id }}/decline" data-turbo-confirm="Отклонить подтверждение {{ $bid->user->shortName() }}?">@csrf<x-ui.button size="sm" variant="ghost">Отклонить</x-ui.button></form>
        </div>
    @endif
</div>
