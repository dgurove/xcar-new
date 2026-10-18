{{-- «Отдать» в гараж (`Give`, 18.10.2026): кому (пусто — «взяли под себя», платим мы), кто платит поставщику (выбор —
     только где он есть: у Совкомбанка и без гаражной ветки платит менеджер, `Offer::garagePayerFixed`), наша доля у
     «платит менеджер» (у Совкомбанка доли нет — нашу часть даёт продажа), где ТС и кто везёт. С подтверждением «В гараж»
     — его принятие: кому — из него. Лист «Отдать» и сегмент «В гараж» карточки «Без продажной цены» (`inline`). --}}
@props(['offer', 'bid' => null, 'prefix' => 'give', 'inline' => false])
@php
    $branch = ! $offer->garagePayerFixed();
    $handOver = $offer->deal;
@endphp
<form method="post" action="{{ $bid ? '/confirmations/'.$bid->id.'/accept' : '/offers/'.$offer->number.'/give' }}" {{ $attributes->class(['flex flex-col gap-4']) }} data-controller="reveal">
    @csrf<input type="hidden" name="to" value="garage">
    @error('give')<x-ui.flash tone="danger">{{ $message }}</x-ui.flash>@enderror
    @unless ($bid)
        <div class="fields">
            <x-ui.combobox name="manager_id" label="Кому" url="/reference/managers" placeholder="Взяли под себя" :id="$prefix.'-garage-manager'"/>
        </div>
    @endunless
    @if ($branch)
        <div class="flex flex-col gap-1.5">
            <span class="field-label">Платит поставщику</span>
            <div class="flex flex-wrap gap-2">
                @foreach (\App\Garage\GaragePayer::cases() as $payer)
                    <label class="choice"><input type="radio" name="payer" value="{{ $payer->value }}" @checked(old('payer', 'us') === $payer->value) data-action="reveal#pick"><span>{{ $payer->label() }}</span></label>
                @endforeach
            </div>
        </div>
    @endif
    @unless ($offer->vendor?->isCommission())
        <div class="fields" data-reveal-target="pane" data-reveal-key="manager" @if ($branch && old('payer', 'us') !== 'manager') hidden @endif>
            <x-ui.field name="share" label="Наша доля, ₽" :value="old('share')" :id="$prefix.'-garage-share'"/>
        </div>
    @endunless
    <x-offer.pickup-choice :offer="$offer" garage :manager="$bid?->user"/>
    @if ($handOver)<p class="text-sm text-ink-muted">Сделка с {{ $handOver->buyer?->shortName() }} отменится</p>@endif
    @if ($inline)
        <x-ui.button type="submit" variant="primary" block>Отдать в гараж</x-ui.button>
    @else
        <x-ui.sheet-foot type="submit" variant="primary">{{ $handOver ? 'Отдать' : 'В гараж' }}</x-ui.sheet-foot>
    @endif
</form>
