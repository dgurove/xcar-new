{{-- «Создать ссылку» (только админ, 06.10.2026: начальник искал, где взять ссылку; до 09.10.2026 — «Взять ссылку на
     оплату», владелец: длинная кнопка уходила отдельной строкой). 07.10.2026 владелец: «на
     телефоне безумно неудобное» — сумма первой и с клавиатурой сразу, чипы услуг одной строкой вбок (выбранный и уходит в чек), поля строками
     (.fields), кнопка липнет к низу листа над клавиатурой. «За что» — услуга в чек (ServiceTitle: конкретная услуга,
     займов, возвратов и денег за машину ссылкой не принимаем), плательщик из заведённых или новый. Выставляет счёт
     ПРАЙМ, ссылка заводится сама, после — карточка ссылки с открытой шторкой («Скопировать», «Отправить»).
     09.10.2026 владелец: ТС по желанию — полем «ТС»: набранное — машина словами, машины системы — подсказкой под ним
     (оплата засчитается в её сделку; шторка поиска на телефоне сдвигала всё и прятала набранное); ссылка по этой ТС или этому плательщику уже открыта — «Ссылка уже есть» и «Открыть ссылку» вместо кнопки. --}}
@php
    use App\Billing\ServiceTitle;
    // Кто уже платил ПРАЙМ (менеджеры, покупатели, прошлые разовые) — а не все контрагенты, включая парковку.
    $parties = \App\Billing\Party::where('is_self', false)->whereHas('invoices', fn ($i) => $i->where('seller', \App\Billing\Seller::Prime->value)->where('direction', 'issued'))
        ->orderBy('name')->pluck('name', 'id')->prepend('Новый плательщик', 'new');
    $partyId = (string) old('party_id', 'new');
    $title = old('title', ServiceTitle::DEFAULT);
@endphp
<div data-controller="sheet" class="contents">
    <button type="button" class="btn btn-s btn-accent rounded-full" data-action="sheet#open" aria-label="Создать ссылку"><x-ui.icon name="plus" class="size-4"/><span class="hidden sm:inline">Создать ссылку</span></button>
    <x-ui.sheet id="service-pay" title="Ссылка на оплату" class="sheet-steady" :open="$errors->hasAny(['amount', 'title', 'party_id', 'party_name', 'party_email', 'offer_id', 'car_title'])">
        <form method="post" action="/work/money/service" class="flex flex-1 flex-col gap-4" data-controller="reveal pay-existing" data-pay-existing-url-value="/work/money/links/existing"
              data-action="input->pay-existing#changed change->pay-existing#changed car-field:chosen->pay-existing#chosen">
            @csrf
            <label class="flex flex-col items-center gap-1">
                <span class="flex items-baseline justify-center gap-2">
                    <x-billing.amount :value="old('amount')" autofocus/>
                    <span class="text-xl text-ink-muted">₽</span>
                </span>
                @error('amount')<span class="text-sm text-danger">{{ $message }}</span>@enderror
            </label>
            <div class="flex flex-col gap-3" data-controller="pay-amount">
                {{-- Услуга в чек — только чипом (07.10.2026, владелец: строка «За что» дублировала пресеты). --}}
                <input type="hidden" name="title" value="{{ $title }}" data-pay-amount-target="input">
                <div class="sheet-chips">
                    @foreach (ServiceTitle::PRESETS as $preset)
                        <button type="button" class="chip" data-action="pay-amount#set" data-pay-amount-value-param="{{ $preset }}" aria-pressed="{{ $preset === $title ? 'true' : 'false' }}">{{ $preset }}</button>
                    @endforeach
                </div>
                @error('title')<div class="text-sm text-danger">{{ $message }}</div>@enderror
                <div class="fields">
                    {{-- ТС — полем, набранное и есть значение; машины системы — подсказкой под ним (`x-billing.car-field`). --}}
                    @php $carOffer = old('offer_id') ? \App\Offers\Offer::find((int) old('offer_id')) : null; @endphp
                    <x-billing.car-field :offer-id="$carOffer?->id" :text="$carOffer ? trim($carOffer->title().' '.$carOffer->year) : old('car_title')" :error="$errors->first('offer_id') ?: $errors->first('car_title')"/>
                    <x-ui.field name="party_id" label="Кто платит" :options="$parties->all()" :value="$partyId" data-action="change->reveal#pick"/>
                    <div class="contents" data-reveal-target="pane" data-reveal-key="new" @if ($partyId !== 'new') hidden @endif>
                        <x-ui.field name="party_name" label="Имя" placeholder="ФИО или название"/>
                        <x-ui.field name="party_email" label="Почта для чека" type="email"/>
                    </div>
                </div>
            </div>
            <div class="sheet-foot mt-auto flex flex-col gap-3">
                <div data-pay-existing-target="box"></div>
                <div data-pay-existing-target="submit"><x-ui.button block>Получить ссылку</x-ui.button></div>
            </div>
        </form>
    </x-ui.sheet>
</div>
