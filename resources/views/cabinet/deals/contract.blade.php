{{-- Покупатель по ДКП у менеджера (05.10.2026): свой покупатель из «Покупателей» или новый; паспорта нет — поля прямо
     тут. Пригласить по ссылке — его приглашения (`/account/invites`), зарегистрированного выбирает здесь же. Продавца и
     документы ТС вносим мы по сканам страховой. --}}
@php
    $contract = \App\Offers\DealContract::for($deal)->loadMissing(['seller', 'buyer.party']);
    $buyer = $contract->buyer;
    $passport = $buyer?->party?->hasPassport();
    $mine = auth()->user()->buyers()->orderBy('name')->get(['id', 'name']);
    $editable = $deal->isActive();
@endphp
<section class="box" id="dkp-buyer">
    <h2 class="box-title">Покупатель по ДКП</h2>
    @if ($buyer)
        <div class="list mt-3">
            <a href="/buyers/{{ $buyer->id }}" class="row">
                <span class="min-w-0 flex-1"><span class="block">{{ $buyer->party?->name ?: $buyer->name }}</span>
                    <span class="row-sub">@if ($passport)<span class="text-open">паспорт есть</span>@else<span class="text-urgent">нужен паспорт</span>@endif</span></span>
                <x-ui.icon name="chevron-right" class="size-4 shrink-0 text-ink-dim"/>
            </a>
        </div>
    @endif
    @if ($editable)
        @if ($buyer && ! $passport)
            <form method="post" action="/deals/{{ $deal->id }}/contract" class="mt-4 flex flex-col gap-3">
                @csrf @method('put')
                <x-billing.passport-fields :party="$buyer->party ?? new \App\Billing\Party(['name' => $buyer->name])" prefix="buyer"/>
                <x-ui.button block>Сохранить паспорт</x-ui.button>
            </form>
        @endif
        <details class="mt-4" @if (! $buyer || $errors->hasAny(['buyer_id', 'buyer.name', 'new_buyer.phone'])) open @endif>
            <summary class="chip">{{ $buyer ? 'Другой покупатель' : 'Выбрать покупателя' }}</summary>
            <div class="mt-3 flex flex-col gap-4">
                @if ($mine->isNotEmpty())
                    <form method="post" action="/deals/{{ $deal->id }}/contract" class="flex flex-col gap-3">
                        @csrf @method('put')
                        <x-ui.field name="buyer_id" label="Ваш покупатель" :options="$mine->pluck('name', 'id')->all()" :value="$buyer?->id"/>
                        <x-ui.button block variant="secondary">Выбрать</x-ui.button>
                    </form>
                @endif
                <form method="post" action="/deals/{{ $deal->id }}/contract" class="flex flex-col gap-3">
                    @csrf @method('put')
                    <input type="hidden" name="buyer_id" value="new">
                    <div class="list-cap !px-0">Новый покупатель</div>
                    <x-ui.field name="new_buyer[phone]" label="Телефон" type="tel"/>
                    <x-billing.passport-fields prefix="buyer" key="new-buyer"/>
                    <x-ui.button block>Добавить покупателя</x-ui.button>
                </form>
                <a href="/account/invites" class="chip self-start">Пригласить по ссылке</a>
            </div>
        </details>
    @endif
</section>
