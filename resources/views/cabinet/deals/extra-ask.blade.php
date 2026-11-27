{{-- Задача сделки, пока её держит доп. просьба поставщику (18.11.2026, владелец: «пока активна дополнительная просьба, ход сделки
     замораживается»; «ему инфу скинули, он окнул и сразу нажал „Беру в работу“ либо отказ, чтобы не спрашивать его заново»;
     `Offers\ExtraAsk`). Вид — как у согласия «Взять в работу?» (`cabinet.deals.exits`): вопрос, что ответил поставщик (`Disclosure`),
     главная кнопка во всю ширину, под ней «Отказываюсь» и «Напомнить через…» в ряд. Пока ждём ответа — «Ждём ответа поставщика», что попросили и
     сколько ждём; кнопка та же, но серая: знает ответ раньше — решает сразу. До согласия — «Беру в работу» / «Отказываюсь» (это и
     ответ шага согласия), после — «Всё в порядке, берём» / «Отказываюсь» (`ExtraAsk::labels`). Просьба после оплаты — только ожидание. --}}
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
    {{-- Он сам просил «Доп. запрос…» (27.11.2026, `ExtraWant`): что отметил — строкой под шапкой, пока ждём письма и ответа. --}}
    @if ($ask->wanted_by && in_array($phase, [ExtraAsk::WANTED, ExtraAsk::SUPPLIER], true))
        <p class="mt-2 text-ink-muted">{{ $phase === ExtraAsk::WANTED ? 'Вы попросили запросить' : 'Запросили у поставщика' }}: {{ \App\Offers\ExtraWant::words($ask, lower: true) }}</p>
    @endif
    @if ($answer->isNotEmpty())@include('cabinet.deals.disclosures', ['disclosures' => $answer, 'early' => collect(), 'late' => $answer])@endif
    @if ($ask->asks_manager)
        <form method="post" action="/deals/{{ $deal->id }}/extra-ask" id="extra-ask-{{ $ask->id }}" class="mt-5 flex flex-col gap-4">
            @csrf
            {{-- «Беру в работу» — окно с тремя кнопками (`x-deal.take-sheet`): «Ещё подумаю…» — напоминание по просьбе (`extra`). --}}
            <x-ui.button type="button" :variant="$decides ? 'primary' : 'secondary'" block data-controller="emit" data-action="emit#send" :data-emit-event-param="'take:take-x-'.$ask->id">{{ Wording::mine($labels['take']) }}</x-ui.button>
        </form>
        <x-deal.take-sheet :id="'take-x-'.$ask->id" :form="'extra-ask-'.$ask->id" :take="['decision', 'take']" :ok="$consented ? 'Да, берём' : 'Да, беру в работу'" :drop="['decision', 'drop']"
            :deal="$decides ? $deal : null" :ask="$ask" extra/>
        {{-- «Отказываюсь» и «Напомнить через…» — двумя кнопками в ряд (19.11.2026, как на шаге сделки): решать ему — с
             напоминанием, ждём поставщика — одна серая кнопка. --}}
        <div class="mt-3 flex gap-3">
            <button form="extra-ask-{{ $ask->id }}" name="decision" value="drop" @class(['btn btn-quiet', 'shrink-0 px-5' => $decides, 'min-w-0 flex-1' => ! $decides]) {{ \App\Offers\Warnings::html(\App\Offers\Warnings::DEAL_OFF, 'Да, отказываюсь', danger: true) }}><span class="truncate">{{ Wording::mine($labels['drop']) }}</span></button>
            @if ($decides)<x-deal.remind :deal="$deal" :ask="$ask" extra/>@endif
        </div>
    @endif
</div>
