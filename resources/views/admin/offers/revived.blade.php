{{-- Вернулось письмом из «Без подтверждений» (18.11.2026, владелец: «кратко, компактно — чтобы понять, что произошло»):
     по какой цене продавали, сколько подтверждений, когда закрыт приём, письмо — окном писем на нём (`ReviveClosed`). --}}
@if ($revived = $offer->revival())
    <p class="detail-line !mt-0 text-sm text-ink-muted">
        @php($said = $revived['bids'] ? 'было '.$revived['bids'].' '.\App\Support\Plural::of($revived['bids'], ['подтверждение', 'подтверждения', 'подтверждений']) : 'подтверждений не было')
        <span>{{ $revived['asking'] ? 'По цене '.\App\Support\Money::rub($revived['asking']).' '.$said : mb_ucfirst($said) }}</span>
        @if ($revived['closed_at'])<span>приём закрыт {!! \App\Support\Ago::html($revived['closed_at'], words: false) !!}</span>@endif
        @if ($revived['letter'])
            <button type="button" class="reprice-letter" data-controller="emit" data-action="emit#send" data-emit-event-param="letters:open" data-emit-url-param="/offers/{{ $offer->number }}/letters?at={{ $revived['letter']->id }}">письмо {!! \App\Support\Ago::html($revived['letter']->date_at, words: false) !!} ›</button>
        @endif
    </p>
@endif
