{{-- Вознаграждение менеджера — итогом карточки денег, крупно зелёным (18.11.2026, владелец: «про вознаграждение куда пропало? мы не
     платим, они сами забирают, но писать, сколько забирать, надо»). Ещё не назначено — «назначим» серым. У супер-покупателя
     вознаграждения не бывает — строки нет. У гаражной «платит менеджер» тоже (20.11.2026): машину он берёт себе, его доход — продажа. --}}
@unless ($deal->isDirect() || $deal->isGarageManager())
    <div class="deal-money-row deal-money-total flex items-baseline gap-3">
        <span class="min-w-0 flex-1">Вознаграждение</span>
        @if ($deal->commission !== null)
            <span class="profit-sum nums shrink-0">+{{ \App\Support\Money::rub((int) $deal->commission) }}</span>
        @else
            <span class="shrink-0 text-ink-muted">назначим</span>
        @endif
    </div>
@endunless
