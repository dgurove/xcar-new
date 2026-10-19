{{-- «Отдать» в гараж (`Give`, 18.10.2026) — по сценарию (19.10.2026, владелец: «если мы под себя берём, то о каком менеджере
     речь — непонятно»): сначала «Кому» — «Менеджеру» или «Себе». Менеджеру: кто, кто платит поставщику (выбор — только где
     он есть: у Совкомбанка и без гаражной ветки платит менеджер, `Offer::garagePayerFixed`), наша доля у «платит менеджер»
     (у Совкомбанка доли нет — нашу часть даёт продажа), где ТС и кто везёт (`x-offer.pickup-choice`). Себе — платим мы, ТС
     у страховой (везёт логистика) или уже у нас: про менеджера ничего. С подтверждением «В гараж» — его принятие: кому — из
     него, «Себе» нет. Лист «Отдать» и «В гараж» в «Ценах» карточки черновика (`inline`). Поля спрятанного сценария reveal
     выключает — в запрос уходит только выбранное. --}}
@props(['offer', 'bid' => null, 'prefix' => 'give', 'inline' => false])
@php
    $branch = ! $offer->garagePayerFixed();
    $handOver = $offer->deal;
    $whom = $bid ? 'manager' : old('whom', 'manager');
    $managerName = $bid?->user?->shortName() ?? 'Менеджер';
@endphp
<form method="post" action="{{ $bid ? '/confirmations/'.$bid->id.'/accept' : '/offers/'.$offer->number.'/give' }}" {{ $attributes->class(['give-garage flex flex-col gap-4']) }} data-controller="reveal">
    @csrf<input type="hidden" name="to" value="garage">
    @error('give')<x-ui.flash tone="danger">{{ $message }}</x-ui.flash>@enderror
    @unless ($bid)
        <div class="flex flex-col gap-1.5">
            <span class="field-label">Кому</span>
            <x-ui.segment name="whom" :options="['manager' => 'Менеджеру', 'self' => 'Себе']" :value="$whom" pick/>
        </div>
    @endunless
    <div class="flex flex-col gap-4" data-reveal-target="pane" data-reveal-key="manager" @if ($whom !== 'manager') hidden @endif>
        @unless ($bid)
            <div class="fields">
                <x-ui.combobox name="manager_id" label="Менеджер" url="/reference/managers" placeholder="Кому отдаём" :id="$prefix.'-garage-manager'"/>
            </div>
        @endunless
        @if ($branch)
            <div class="flex flex-col gap-4" data-controller="reveal">
                <div class="flex flex-col gap-1.5">
                    <span class="field-label">Платит поставщику</span>
                    <div class="flex flex-wrap gap-2">
                        @foreach (\App\Garage\GaragePayer::cases() as $payer)
                            <label class="choice"><input type="radio" name="payer" value="{{ $payer->value }}" @checked(old('payer', 'us') === $payer->value) data-action="reveal#pick"><span>{{ $payer === \App\Garage\GaragePayer::Manager ? $managerName : $payer->label() }}</span></label>
                        @endforeach
                    </div>
                </div>
                @unless ($offer->vendor?->isCommission())
                    <div class="fields" data-reveal-target="pane" data-reveal-key="manager" @if (old('payer', 'us') !== 'manager') hidden @endif>
                        <x-ui.field name="share" label="Наша доля, ₽" :value="old('share')" :id="$prefix.'-garage-share'"/>
                    </div>
                @endunless
            </div>
        @endif
    </div>
    {{-- Где ТС и кто везёт — один кусок на оба сценария: «Себе» — «Уже у нас» и без «Везёт» (только логистика), CSS по
         выбранному «Кому» (`.give-garage`). --}}
    <x-offer.pickup-choice :offer="$offer" garage :manager="$bid?->user"/>
    @if ($handOver)<p class="text-sm text-ink-muted">Сделка с {{ $handOver->buyer?->shortName() }} отменится</p>@endif
    @if ($inline)
        <x-ui.button type="submit" variant="primary" block>Отдать в гараж</x-ui.button>
    @else
        <x-ui.sheet-foot type="submit" variant="primary">{{ $handOver ? 'Отдать' : 'В гараж' }}</x-ui.sheet-foot>
    @endif
</form>
