{{-- Путь сделки — своим куском, не внутри шага: на телефоне между задачей и путём стоят расчёт и покупатель. Чей ход
     у текущего этапа не пишем: его уже говорит шапка шага (11.10.2026, «ждём поставщика» стояло дважды).
     На фоне, без коробки (11.11.2026, владелец: «путь в нормальных приложениях делают просто на фоне»), и ответы менеджера —
     его пунктами с датой и временем, а не отдельной группой «Ваши ответы»: «Подтверждение принято», «Беру в работу». --}}
@php
    // Конечный этап (выходов нет — «Сделка закрыта») пройден: в пути он галочкой, как в CRM.
    $currentBlock = $position?->stage->exits->isNotEmpty() ? $position->stage->block_id : null;
    // Ответы — только его: шаги поставщика и наши менеджеру не пересказываем (03.11.2026), их видно по блокам с датой.
    $stageBlocks = $blocks->flatMap(fn ($b) => $b->stages->map(fn ($s) => [$s->id, $b->id]))->mapWithKeys(fn ($p) => [$p[0] => $p[1]]);
    $answers = $deal->requirements->whereNotNull('done_at')->filter(fn ($r) => ! empty($r->answer['exit']))
        ->map(fn ($r) => [
            'block' => $stageBlocks->get($r->stage_id),
            'at' => $r->done_at,
            'title' => \App\Users\Wording::mine($r->answer['label'] ?? $r->answer['exit'])
                .(! empty($r->answer['fields']) ? ': '.collect($r->answer['fields'])->map(fn ($v, $k) => $r->shown($k, $v))->implode(', ')
                    // Время подписания — выбранным временем (`Offers\Signing`).
                    : (mb_strtolower($r->answer['exit']) === \App\Offers\Signing::PICK_EXIT && $deal->signing_at ? ': '.\App\Support\Ago::at($deal->signing_at, true, words: false) : '')),
            'note' => match ($r->answer['closed_by'] ?? null) { 'staff' => 'за вас', 'payment' => 'оплата отметилась сама', default => null },
            'files' => $r->getMedia('files'),
        ])->filter(fn ($a) => $a['block'] !== null)->sortBy('at')->values();
    // Сделка началась — «Подтверждение принято» первым пунктом первого блока.
    $first = $blocks->first()?->id;
    if ($first && $deal->created_at) {
        $answers->prepend(['block' => $first, 'at' => $deal->created_at, 'title' => 'Подтверждение принято', 'note' => null, 'files' => collect()]);
    }
@endphp
@if ($blocks->isNotEmpty())
    <section id="path" class="scroll-mt-4 min-w-0 {{ $class ?? '' }}">
        <h2 class="list-head">Путь сделки</h2>
        <div><x-route.timeline :blocks="$blocks" :current="$currentBlock" :steps="$steps" :deal="$deal" :answers="$answers"/></div>
    </section>
@endif
