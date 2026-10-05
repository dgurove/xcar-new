{{-- Покупатель для договора у менеджера (05.10.2026): у ДКП — с кем подписывает собственник, у ПРАЙМ — кому счёт и ДКП
     ПРАЙМ. Свой покупатель из «Покупателей», сам менеджер или новый; физлицо с паспортом или организация — данных нет,
     поля прямо тут. У ПРАЙМ — кто платит по счёту: покупатель или менеджер за вычетом своего вознаграждения. Счёт и
     договор собираются сами (`SaveDealContract` → `SyncDealInvoices`). Пригласить по ссылке — его приглашения. --}}
@php
    $contract = \App\Offers\DealContract::for($deal)->loadMissing(['seller', 'buyer.party']);
    $buyer = $contract->buyer;
    $me = auth()->user();
    $isMe = $buyer && $buyer->id === $me->id;
    $ready = $buyer?->party?->readyForContract();
    $mine = $me->buyers()->orderBy('name')->get(['id', 'name']);
    $prime = $deal->isPrime();
    $editable = $deal->isActive();
    $options = ['me' => 'Я сам'] + $mine->pluck('name', 'id')->all();
@endphp
<section class="box" id="dkp-buyer">
    <h2 class="box-title">{{ $prime ? 'Покупатель: счёт и ДКП' : 'Покупатель по ДКП' }}</h2>
    @if ($buyer)
        <div class="list mt-3">
            <a href="{{ $isMe ? '/account/money/details' : '/buyers/'.$buyer->id }}" class="row">
                <span class="min-w-0 flex-1"><span class="block">{{ $isMe ? 'Я сам' : ($buyer->party?->name ?: $buyer->name) }}</span>
                    <span class="row-sub">@if ($ready)<span class="text-open">данные есть</span>@else<span class="text-urgent">нужны данные для договора</span>@endif</span></span>
                <x-ui.icon name="chevron-right" class="size-4 shrink-0 text-ink-dim"/>
            </a>
        </div>
        @if ($prime && ! $isMe && $editable)
            {{-- Кто платит по счёту ПРАЙМ: покупатель (вознаграждение выплатим) или сам менеджер за вычетом своего. --}}
            <form method="post" action="/deals/{{ $deal->id }}/contract" class="mt-3 flex flex-wrap items-center gap-2" data-controller="autosubmit">
                @csrf @method('put')
                <span class="text-sm text-ink-muted">Платит по счёту</span>
                <label class="choice"><input type="radio" name="payer" value="buyer" @checked(! $contract->managerPays()) data-action="change->autosubmit#submit"><span>покупатель</span></label>
                <label class="choice"><input type="radio" name="payer" value="manager" @checked($contract->managerPays()) data-action="change->autosubmit#submit"><span>я</span></label>
            </form>
        @endif
    @endif
    @if ($editable)
        @if ($buyer && ! $ready)
            <form method="post" action="/deals/{{ $deal->id }}/contract" class="mt-4 flex flex-col gap-3">
                @csrf @method('put')
                <x-billing.buyer-fields :party="$buyer->party ?? new \App\Billing\Party(['name' => $buyer->name])" key="buyer-now"/>
                <x-ui.button block>Сохранить</x-ui.button>
            </form>
        @endif
        <details class="mt-4" @if (! $buyer || $errors->hasAny(['buyer_id', 'buyer.name', 'new_buyer.phone'])) open @endif>
            <summary class="chip">{{ $buyer ? 'Другой покупатель' : 'Выбрать покупателя' }}</summary>
            <div class="mt-3 flex flex-col gap-4">
                <form method="post" action="/deals/{{ $deal->id }}/contract" class="flex flex-col gap-3">
                    @csrf @method('put')
                    <x-ui.field name="buyer_id" label="Покупатель" :options="$options" :value="$isMe ? 'me' : $buyer?->id"/>
                    <x-ui.button block variant="secondary">Выбрать</x-ui.button>
                </form>
                <form method="post" action="/deals/{{ $deal->id }}/contract" class="flex flex-col gap-3">
                    @csrf @method('put')
                    <input type="hidden" name="buyer_id" value="new">
                    <div class="list-cap !px-0">Новый покупатель</div>
                    <x-ui.field name="new_buyer[phone]" label="Телефон" type="tel"/>
                    <x-billing.buyer-fields key="new-buyer"/>
                    <x-ui.button block>Добавить покупателя</x-ui.button>
                </form>
                <a href="/account/invites" class="chip self-start">Пригласить по ссылке</a>
            </div>
        </details>
    @endif
</section>
