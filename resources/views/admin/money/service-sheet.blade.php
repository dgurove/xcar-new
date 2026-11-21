{{-- «Создать ссылку» (только админ, 06.10.2026: начальник искал, где взять ссылку). Сумма первой и с клавиатурой сразу, чипы
     услуг одной строкой вбок (выбранный и уходит в чек), поля строками (.fields), кнопка липнет к низу листа над клавиатурой.
     «За что» — услуга в чек (ServiceTitle: конкретная услуга, займов, возвратов и денег за машину ссылкой не принимаем).
     ТС — полем «ТС»: набранное — машина словами, машины системы — подсказкой под ним (оплата засчитается в её сделку); ссылка по
     этой ТС уже открыта — «Ссылка уже есть» и «Открыть ссылку» вместо кнопки.
     22.11.2026, владелец: плательщика не спрашиваем («марку, модель и сумму указываем, а плательщика они сами указывают, когда по
     ссылке переходят») — почту для чека вводит тот, кто платит, на `/pay`; «Менеджер» — чья ссылка (у ТС со сделкой встаёт сам);
     со страницы человека он уже выбран (`manager`), после «Получить ссылку» — туда же, откуда создавали (`back`). --}}
@php
    use App\Billing\ServiceTitle;
    $manager ??= null;
    $managers = once(fn () => \App\Users\User::withRole(\App\Users\Role::Manager)->orderBy('name')->pluck('name', 'id'));
    $title = old('title', ServiceTitle::DEFAULT);
@endphp
<div data-controller="sheet" class="contents">
    <button type="button" class="btn btn-s btn-accent rounded-full" data-action="sheet#open" aria-label="Создать ссылку"><x-ui.icon name="plus" class="size-4"/><span class="hidden sm:inline">Создать ссылку</span></button>
    <x-ui.sheet id="service-pay" title="Ссылка на оплату" class="sheet-steady">
        <form method="post" action="/work/money/service" class="flex flex-1 flex-col gap-4" data-controller="pay-existing" data-pay-existing-url-value="/work/money/links/existing"
              data-action="input->pay-existing#changed change->pay-existing#changed car-field:chosen->pay-existing#chosen">
            @csrf
            <input type="hidden" name="back" value="{{ request()->getRequestUri() }}">
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
                    <x-ui.field name="manager_id" label="Менеджер" :options="$managers" :value="old('manager_id', $manager?->id)" placeholder="Без менеджера"/>
                </div>
            </div>
            <div class="sheet-foot mt-auto flex flex-col gap-3">
                <div data-pay-existing-target="box"></div>
                <div data-pay-existing-target="submit"><x-ui.button block>Получить ссылку</x-ui.button></div>
            </div>
        </form>
    </x-ui.sheet>
</div>
