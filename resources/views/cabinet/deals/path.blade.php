{{-- Путь сделки — своим куском, не внутри шага: на телефоне между задачей и путём стоят расчёт и покупатель. Чей ход
     у текущего этапа не пишем: его уже говорит шапка шага (11.10.2026, «ждём поставщика» стояло дважды). --}}
@php
    // Конечный этап (выходов нет — «Сделка закрыта») пройден: в пути он галочкой, как в CRM.
    $currentBlock = $position?->stage->exits->isNotEmpty() ? $position->stage->block_id : null;
@endphp
@if ($blocks->isNotEmpty())
    <div class="box {{ $class ?? '' }}">
        <h2 class="box-title">Путь сделки</h2>
        <div class="mt-3"><x-route.timeline :blocks="$blocks" :current="$currentBlock" :steps="$steps" :deal="$deal"/></div>
    </div>
@endif
