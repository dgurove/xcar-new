{{-- «Продана на аукционе» (20.10.2026, Совкомбанк) — третья дорожка редактора и окошка вместо «Сделки»: кто купил, когда;
     пока по счёту не платили — «Отменить продажу на аукционе» (`UndoAuctionSale`). Деньги — `x-deal.money` под ней. --}}
@php
    $buyer = $deal->auctionBuyer;
    $undo = $deal->state === \App\Offers\DealState::Done && auth()->user()?->canManageCrm() && \App\Offers\Actions\UndoAuctionSale::dealOf($offer)?->is($deal);
@endphp
<x-ui.card title="Продана на аукционе" id="deal" class="scroll-mt-24">
    <div class="list">
        <div class="row">
            <span class="min-w-0 flex-1">
                <span class="block">{{ $buyer?->name ?? 'Покупатель не указан' }}</span>
                @if ($buyer?->inn || $buyer?->phone)
                    <span class="row-sub">@if ($buyer->inn)<x-ui.copy-code :value="$buyer->inn" :text="'ИНН '.$buyer->inn" done="ИНН в буфере"/>@endif @if ($buyer->phone)<a href="tel:+{{ $buyer->phone }}" class="text-accent-text">{{ \App\Support\Phone::format($buyer->phone) }}</a>@endif</span>
                @endif
            </span>
            <span class="shrink-0 text-sm text-ink-muted">{{ \App\Support\Ago::html($deal->auction_at, words: false) }}</span>
        </div>
        @if ($deal->state === \App\Offers\DealState::Cancelled)
            <div class="row text-ink-muted">Продажа отменена</div>
        @elseif ($undo)
            <form method="post" action="/offers/{{ $offer->number }}/auction/sold" class="contents" data-turbo-confirm="Отменить продажу на аукционе?" data-turbo-confirm-text="Счёт аннулируется, ТС снова на аукционе, вывоз снова ждёт исполнителя" data-turbo-confirm-label="Отменить">
                @csrf @method('delete')
                <button class="row row-danger w-full text-left">Отменить продажу на аукционе</button>
            </form>
        @endif
    </div>
</x-ui.card>
