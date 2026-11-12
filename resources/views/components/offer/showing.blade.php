{{-- «Показ» — один модуль (12.10.2026, владелец: «бесит, что „Кому показывать“ в одном месте, а в сделке в другом»; 12.11.2026 —
     «это всё одно и то же», не хватало срока и «всем, кроме»). У предложения: срок обязывающего (у вендора по комиссии), «Приём
     подтверждений до» (`x-offer.close-field`), «Кому показывать» (`x-audience.field`: «Всем | Всем, кроме | Выбранным», волны),
     ниже галки показа. У машины в сделке (и гаражной, пока не привезли) — публикация для других менеджеров строкой: идёт — до
     когда и кому, нет — «Не опубликована»; нажатие — шторка `x-offer.deal-publish-sheet` с теми же полями. Архив и прочее — модуля нет.
     Стоит справа под «Ценами» в редакторе (`as=card`) и разделом в окошках (`as=section`); у черновика и открытого — полями
     шторки «Показ» за строкой-сводкой (`as=sheet`, `x-offer.showing-row`, 19.10.2026): там «Кому» сразу полями, без шторки в
     шторке. Поля ходят в форму `form` атрибутом. Только админу. --}}
@props(['offer', 'form', 'as' => 'card', 'summary' => null])
@php
    use App\Offers\{AudienceRules, OfferState};
    use App\Offers\Actions\PublishDeal;
    $mode = match (true) {
        ! auth()->user()->canManageCrm() => null,
        PublishDeal::allows($offer) => 'deal',
        ! $offer->deal && ! $offer->garageCar && in_array($offer->state, [OfferState::Draft, OfferState::Gallery, OfferState::Open], true) => 'offer',
        default => null,
    };
    $n = $offer->number;
@endphp
@if ($mode)
<section {{ $attributes->class(['box' => $as === 'card']) }}>
    @if ($as === 'sheet')
    @elseif ($as === 'card')
        <div class="mb-3"><h2 class="box-title">Показ</h2></div>
    @else
        <h2 class="detail-section">Показ</h2>
    @endif
    @if ($mode === 'deal')
        {{-- Публикация для других менеджеров — своей шторкой (`PublishDeal`): поля в форму сделки не идут. --}}
        @php $on = $offer->showsDealAsOpen(); @endphp
        <button type="button" class="showing-row" data-controller="emit" data-action="emit#send" data-emit-event-param="deal-publish:open">
            <x-ui.icon name="users" class="size-5 shrink-0 text-ink-muted"/>
            <span class="min-w-0 flex-1">
                @if ($on)
                    <span class="block"><x-offer.published-for :offer="$offer" cap/></span>
                    <span class="block text-sm text-ink-muted">{{ AudienceRules::summary(AudienceRules::forDeal($offer)) }}</span>
                @else
                    <span class="block">Не опубликована для других менеджеров</span>
                @endif
            </span>
            <x-ui.icon name="chevron-right" class="size-4 shrink-0 text-ink-dim"/>
        </button>
    @else
    {{-- Блок пришёл в форме: снятые галки — снятые (`OfferRequest::payload`). --}}
    <input type="hidden" name="_show" value="1" form="{{ $form }}">
    <div class="grid grid-cols-[repeat(2,minmax(0,1fr))] gap-x-2 gap-y-3">
        {{-- Срок обязывающего (19.10.2026, владелец: «туда хорошо подходит») — общим куском `x-offer.binding-field`. --}}
        <x-offer.binding-field :offer="$offer" :id="'f-binding_until-'.$n.'-'.$as" :form="$form" span="col-span-2"/>
        {{-- Не вписан — посчитает публикация (`Slots::closeFor`), поэтому без умолчания. --}}
        <x-offer.close-field :offer="$offer" :id="'f-bids_close_at-'.$n.'-'.$as" :value="$offer->bids_close_at?->format('Y-m-d\TH:i') ?? ''" :required="false" :form="$form" span="col-span-2"/>
        <x-audience.field :value="$offer->audience_rules ? json_encode($offer->audience_rules) : ''" :rules="AudienceRules::of($offer)" :form="$form"
            :inline="$as === 'sheet'" :sheet="'audience-'.$n.'-'.$as" class="col-span-2"/>
        @if (($summary ?? collect())->isNotEmpty())
            <div class="col-span-2 flex flex-col gap-1.5 text-sm">
                @foreach ($summary as $row)
                    <div class="flex items-center gap-2"><x-ui.person :user="$row['manager']"/><span class="text-ink-muted">открыл {{ $row['buyers'] }} {{ \App\Support\Plural::of($row['buyers'], ['покупателю', 'покупателям', 'покупателям']) }}</span></div>
                @endforeach
            </div>
        @endif
        <div class="flags col-span-2">
            <x-ui.check name="recommended" :checked="$offer->recommended" :form="$form">Рекомендуем</x-ui.check>
            <x-ui.check name="chat_enabled" :checked="$offer->chat_enabled" :form="$form">Чат с покупателями</x-ui.check>
            <x-ui.check name="share_locked" :checked="$offer->share_locked" :form="$form">Запретить шеринг</x-ui.check>
            <x-ui.check name="garage_allowed" :checked="$offer->garage_allowed" :form="$form">Можно в гараж</x-ui.check>
        </div>
    </div>
    @endif
</section>
@endif
