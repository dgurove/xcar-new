{{-- Покупатель в карточке денег менеджера — строкой последней (18.11.2026, владелец выбрал «квитанцию»: цена → кому платят →
     вознаграждение → покупатель). Пока его нет — задача «Укажите покупателя» под шагом, здесь ничего; на странице нет другой шторки
     выбора — своя (`buyerPickInMoney`), иначе строка шлёт `buyer:open`. --}}
@php $cb = $deal->contract?->buyer; @endphp
@unless ($buyerTask ?? false)
<div class="deal-money-buyer">
    @if (($pickBuyer ?? false) || ($buyerPickInMoney ?? false))
        {{-- «Страховой напрямую» (16.11.2026) и до согласования (18.11.2026) — своя шторка выбора: на странице другой нет. --}}
        @if (! $deal->contract?->buyer_user_id && $deal->isLive())<x-deal.buyer-kind :deal="$deal"/>@endif
        @include('cabinet.deals.contract', ['part' => 'buyer', 'embedded' => true, 'labels' => true, 'capped' => true, 'after' => ''])
    @else
        {{-- Подпись над плашкой, в плашке — имя и фамилия и есть ли данные (18.11.2026, владелец). --}}
        @php $cbReady = (bool) $cb?->party?->readyAsBuyer(); @endphp
        <div class="list-cap !px-0 flex justify-between gap-3"><span>Покупатель</span>@if ($cb)<span @class(['text-open' => $cbReady, 'text-urgent' => ! $cbReady])>{{ $cbReady ? 'данные есть' : ($cb->party?->gapWord() ?? 'нужны данные') }}</span>@endif</div>
        <div class="list">
            <button type="button" class="row w-full text-left" data-controller="emit" data-action="emit#send" data-emit-event-param="buyer:open">
                <span @class(['min-w-0 flex-1 truncate', 'text-danger' => ! $cb])>{{ $cb ? ($cb->id === $deal->buyer_id ? 'Вы' : $cb->buyerLabel()) : 'не выбран' }}</span>
                <x-ui.chevron/>
            </button>
        </div>
    @endif
</div>
@endunless
