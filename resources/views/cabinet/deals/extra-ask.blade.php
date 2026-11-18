{{-- Задача сделки, пока её держит доп. просьба поставщику (18.11.2026, владелец: «пока активна дополнительная просьба, ход сделки
     замораживается»; «ему инфу скинули, он окнул и сразу нажал „Беру в работу“ либо отказ, чтобы не спрашивать его заново»;
     `Offers\ExtraAsk`). Вид — как у согласия «Взять в работу?» (`cabinet.deals.exits`): вопрос, что ответил поставщик (`Disclosure`),
     главная кнопка во всю ширину и тихой строкой «Я передумал». Пока ждём ответа — «Ждём ответа поставщика», что попросили и
     сколько ждём; кнопка та же, но серая: знает ответ раньше — решает сразу. До согласия — «Беру в работу» / «Я передумал» (это и
     ответ шага согласия), после — «Всё в порядке, берём» / «Не берём» (`ExtraAsk::labels`). Просьба после оплаты — только ожидание. --}}
@php
    use App\Offers\ExtraAsk;
    use App\Users\Wording;
    $phase = $ask->phase();
    $labels = ExtraAsk::labels($deal);
    $consented = ExtraAsk::consented($deal);
    $decides = $phase === ExtraAsk::MANAGER;
    // Что показали из ответа на эту просьбу — после неё.
    $answer = $decides ? ($disclosures ?? collect())->filter(fn ($d) => $d->created_at->gte($ask->asked_at))->values() : collect();
@endphp
<div id="step" class="box scroll-mt-4">
    <h2>{{ $decides ? ($consented ? 'Берёте после ответа поставщика?' : 'Взять в работу?') : 'Ждём ответа поставщика' }}</h2>
    @if ($decides)
        <p class="mt-1 text-sm text-ink-muted">Поставщик ответил на просьбу {{ $ask->phrase() }}</p>
    @else
        <p class="mt-1 text-sm text-ink-muted">Попросили {{ $ask->phrase() }} <span class="nums whitespace-nowrap" data-controller="timer" data-timer-since-value="{{ $ask->since_at->toIso8601String() }}" data-timer-coarse-value="true">{{ \App\Support\Ago::since($ask->since_at) }}</span></p>
    @endif
    @if ($answer->isNotEmpty())@include('cabinet.deals.disclosures', ['disclosures' => $answer, 'early' => collect(), 'late' => $answer])@endif
    @if ($ask->asks_manager)
        <form method="post" action="/deals/{{ $deal->id }}/extra-ask" class="mt-5 flex flex-col gap-4">
            @csrf
            <x-ui.button name="decision" value="take" :variant="$decides ? 'primary' : 'secondary'" block :data-turbo-confirm="$consented ? Wording::mine($labels['take']).'?' : 'Взять в работу? После этого отказаться нельзя'">{{ Wording::mine($labels['take']) }}</x-ui.button>
            <button name="decision" value="drop" class="retreat-link" data-turbo-confirm="Передумали? Сделка отменится">{{ Wording::mine($labels['drop']) }}</button>
        </form>
    @endif
</div>
