{{-- Разовая оплата по ссылке (только админ): сумма крупно, «За что» — услуга в чек, чипы — наши услуги по подбору ТС
     (ServiceTitle: в чеке конкретная услуга, займов, возвратов и денег за машину ссылкой не принимаем), плательщик из
     заведённых или новый. Выставляет счёт ПРАЙМ, ссылка заводится сама, после — карточка счёта со ссылкой. --}}
@php
    use App\Billing\ServiceTitle;
    $parties = \App\Billing\Party::where('is_self', false)->orderBy('name')->pluck('name', 'id')->prepend('Новый плательщик', 'new');
    $partyId = (string) old('party_id', 'new');
@endphp
<div data-controller="sheet" class="contents">
    <button type="button" class="btn btn-s btn-accent rounded-full" data-action="sheet#open"><x-ui.icon name="plus" class="size-4"/><span class="hidden sm:inline">Оплата по ссылке</span></button>
    <x-ui.sheet id="service-pay" title="Оплата по ссылке" :open="$errors->hasAny(['amount', 'title', 'party_id', 'party_name', 'party_email', 'party_phone'])">
        <form method="post" action="/work/money/service" class="flex flex-col gap-5" data-controller="reveal">
            @csrf
            <div class="flex flex-col items-center gap-2">
                <label class="flex w-full items-baseline justify-center gap-2">
                    <input name="amount" inputmode="decimal" autocomplete="off" class="pay-amount nums" aria-label="Сумма, ₽" placeholder="0" value="{{ old('amount') }}" data-controller="digits" data-action="input->digits#format" autofocus>
                    <span class="text-2xl text-ink-muted">₽</span>
                </label>
                @error('amount')<div class="text-sm text-danger">{{ $message }}</div>@enderror
            </div>
            <div class="flex flex-col gap-2" data-controller="pay-amount">
                <x-ui.field name="title" label="За что" :value="old('title', ServiceTitle::DEFAULT)" maxlength="128" data-pay-amount-target="input"/>
                <div class="flex flex-wrap gap-2">
                    @foreach (ServiceTitle::PRESETS as $preset)
                        <button type="button" class="chip" data-action="pay-amount#set" data-pay-amount-value-param="{{ $preset }}">{{ $preset }}</button>
                    @endforeach
                </div>
            </div>
            <x-ui.field name="party_id" label="Кто платит" :options="$parties->all()" :value="$partyId" data-action="change->reveal#pick"/>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2" data-reveal-target="pane" data-reveal-key="new" @if ($partyId !== 'new') hidden @endif>
                <x-ui.field name="party_name" label="ФИО или название" span="sm:col-span-2"/>
                <x-ui.field name="party_email" label="Почта для чека" type="email"/>
                <x-ui.field name="party_phone" label="Телефон" type="tel"/>
            </div>
            <x-ui.button block>Выставить и получить ссылку</x-ui.button>
        </form>
    </x-ui.sheet>
</div>
