{{-- «Показ» строкой — одна дверь для блока «Цены» страницы и окна машины в любом состоянии (23.11.2026, владелец: «иногда показ
     объединён с блоком цен, иногда нет — показ в любом случае объединять»; был строкой у черновика, строкой в плашке «Приём» у
     открытого и отдельной карточкой у сделки и слота). Только админу (`canManageCrm`):
     - машина в сделке или гараже, где можно опубликовать для других (`PublishDeal::allows`), — публикация для других менеджеров:
       идёт — до когда и кому, нет — «Не опубликована»; нажатие — шторка `x-offer.deal-publish-sheet` (`admin.car.sheets`);
     - черновик, галерея, открытое без сделки — `x-offer.showing-row` (срок приёма, кому, отметки; шторка «Показ» с полями);
     - иначе ничего. `when=false` — без срока (идёт приём: срок в заголовке плашки «Приём»). --}}
@props(['offer', 'form', 'summary' => null, 'when' => true])
@php
    use App\Offers\{AudienceRules, OfferState};
    $mode = match (true) {
        ! auth()->user()?->canManageCrm() => null,
        \App\Offers\Actions\PublishDeal::allows($offer) => 'deal',
        ! $offer->deal && ! $offer->garageCar && in_array($offer->state, [OfferState::Draft, OfferState::Gallery, OfferState::Open], true) => 'offer',
        default => null,
    };
@endphp
@if ($mode === 'deal')
    @php $on = $offer->showsDealAsOpen(); @endphp
    <button type="button" {{ $attributes->class(['showing-row']) }} data-controller="emit" data-action="emit#send" data-emit-event-param="deal-publish:open">
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
@elseif ($mode === 'offer')
    <x-offer.showing-row :offer="$offer" :form="$form" :summary="$summary" :when="$when" {{ $attributes }}/>
@endif
