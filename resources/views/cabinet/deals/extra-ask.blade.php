{{-- Задача сделки, пока её держит доп. просьба поставщику (18.11.2026, владелец: «пока активна дополнительная просьба, ход сделки
     замораживается»; «ему инфу скинули, он окнул и сразу нажал „Беру в работу“ либо отказ, чтобы не спрашивать его заново»;
     `Offers\ExtraAsk`). Вид — как у согласия «Взять в работу?» (`cabinet.deals.exits`): вопрос, что ответил поставщик (`Disclosure`),
     главная кнопка во всю ширину, под ней «Я передумал» и «Напомнить через…» в ряд. Пока ждём ответа — «Ждём ответа поставщика», что попросили и
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
    {{-- Шапка — чей ход, как у шага сделки (`x-deal.turn-head`, 18.11.2026, владелец: «есть ЕГО шаг и НАШ шаг, ВСЁ»): ждём
         поставщика — наш ход «На согласовании» / «В обработке» со счётчиком, без «Попросили …»; решать ему — вопрос «Поставщик ответил —
         берёте?» (`ExtraAskDecisionNotice::question`), ниже сам ответ. --}}
    @if ($turn = \App\Offers\Turn::of($deal))<x-deal.turn-head :turn="$turn"/>@endif
    @if ($answer->isNotEmpty())@include('cabinet.deals.disclosures', ['disclosures' => $answer, 'early' => collect(), 'late' => $answer])@endif
    @if ($ask->asks_manager)
        <form method="post" action="/deals/{{ $deal->id }}/extra-ask" id="extra-ask-{{ $ask->id }}" class="mt-5 flex flex-col gap-4">
            @csrf
            <x-ui.button name="decision" value="take" :variant="$decides ? 'primary' : 'secondary'" block :data-turbo-confirm="$consented ? \App\Offers\TakeWarning::TITLE_AFTER : \App\Offers\TakeWarning::TITLE" :data-turbo-confirm-text="\App\Offers\TakeWarning::TEXT" :data-turbo-confirm-label="$consented ? Wording::mine($labels['take']) : \App\Offers\TakeWarning::OK" :data-turbo-confirm-cancel="\App\Offers\TakeWarning::CANCEL">{{ Wording::mine($labels['take']) }}</x-ui.button>
        </form>
        {{-- «Я передумал» / «Не берём» и «Напомнить через…» — двумя кнопками в ряд (19.11.2026, как на шаге сделки): решать ему — с
             напоминанием, ждём поставщика — одна серая кнопка. --}}
        <div class="mt-3 flex gap-3">
            <button form="extra-ask-{{ $ask->id }}" name="decision" value="drop" @class(['btn btn-quiet', 'shrink-0 px-5' => $decides, 'min-w-0 flex-1' => ! $decides]) data-turbo-confirm="Передумали? Сделка отменится" data-turbo-confirm-label="{{ Wording::mine($labels['drop']) }}"><span class="truncate">{{ Wording::mine($labels['drop']) }}</span></button>
            @if ($decides)<x-deal.remind :deal="$deal" :ask="$ask" extra/>@endif
        </div>
    @endif
</div>
