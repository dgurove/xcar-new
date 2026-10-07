{{-- «Взять ссылку на оплату» (только админ, 06.10.2026: начальник искал, где взять ссылку). 07.10.2026 владелец: «на
     телефоне безумно неудобное» — сумма первой и с клавиатурой сразу, чипы услуг одной строкой вбок, поля строками
     (.fields), кнопка липнет к низу листа над клавиатурой. «За что» — услуга в чек (ServiceTitle: конкретная услуга,
     займов, возвратов и денег за машину ссылкой не принимаем), плательщик из заведённых или новый. Выставляет счёт
     ПРАЙМ, ссылка заводится сама, после — карточка счёта со ссылкой («Скопировать», «Отправить»). --}}
@php
    use App\Billing\ServiceTitle;
    // Кто уже платил ПРАЙМ (менеджеры, покупатели, прошлые разовые) — а не все контрагенты, включая парковку.
    $parties = \App\Billing\Party::where('is_self', false)->whereHas('invoices', fn ($i) => $i->where('seller', \App\Billing\Seller::Prime->value)->where('direction', 'issued'))
        ->orderBy('name')->pluck('name', 'id')->prepend('Новый плательщик', 'new');
    $partyId = (string) old('party_id', 'new');
    $title = old('title', ServiceTitle::DEFAULT);
@endphp
<div data-controller="sheet" class="contents">
    <button type="button" class="btn btn-s btn-accent rounded-full" data-action="sheet#open"><x-ui.icon name="plus" class="size-4"/><span>Взять ссылку на оплату</span></button>
    <x-ui.sheet id="service-pay" title="Ссылка на оплату" :open="$errors->hasAny(['amount', 'title', 'party_id', 'party_name', 'party_email'])">
        <form method="post" action="/work/money/service" class="flex flex-col gap-4" data-controller="reveal">
            @csrf
            <label class="flex flex-col items-center gap-1">
                <span class="flex items-baseline justify-center gap-2">
                    <input name="amount" inputmode="decimal" autocomplete="off" class="pay-amount nums" aria-label="Сумма, ₽" placeholder="0" value="{{ old('amount') }}" data-controller="digits" data-action="input->digits#format" autofocus>
                    <span class="text-xl text-ink-muted">₽</span>
                </span>
                @error('amount')<span class="text-sm text-danger">{{ $message }}</span>@enderror
            </label>
            <div class="flex flex-col gap-3" data-controller="pay-amount">
                <div class="sheet-chips">
                    @foreach (ServiceTitle::PRESETS as $preset)
                        <button type="button" class="chip" data-action="pay-amount#set" data-pay-amount-value-param="{{ $preset }}" aria-pressed="{{ $preset === $title ? 'true' : 'false' }}">{{ $preset }}</button>
                    @endforeach
                </div>
                <div class="fields">
                    <x-ui.field name="title" label="За что" :value="$title" maxlength="128" data-pay-amount-target="input" data-action="input->pay-amount#sync"/>
                    <x-ui.field name="party_id" label="Кто платит" :options="$parties->all()" :value="$partyId" data-action="change->reveal#pick"/>
                    <div class="contents" data-reveal-target="pane" data-reveal-key="new" @if ($partyId !== 'new') hidden @endif>
                        <x-ui.field name="party_name" label="Имя" placeholder="ФИО или название"/>
                        <x-ui.field name="party_email" label="Почта для чека" type="email"/>
                    </div>
                </div>
            </div>
            <div class="sheet-foot"><x-ui.button block>Получить ссылку</x-ui.button></div>
        </form>
    </x-ui.sheet>
</div>
