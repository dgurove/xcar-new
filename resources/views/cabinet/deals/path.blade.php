{{-- Путь сделки — своим куском, не внутри шага: на телефоне между задачей и путём стоят расчёт и покупатель. --}}
@php
    // Конечный этап (выходов нет — «Сделка закрыта») пройден: в пути он галочкой, как в CRM.
    $currentBlock = $position?->stage->exits->isNotEmpty() ? $position->stage->block_id : null;
    $waiting = $position ? match ($position->waitsFor()) {
        \App\Workflow\WaitsFor::Manager => 'Ваш ход', \App\Workflow\WaitsFor::Supplier => 'ждём поставщика', \App\Workflow\WaitsFor::Us => 'ждём нас', default => null,
    } : null;
@endphp
@if ($blocks->isNotEmpty())
    <div class="box {{ $class ?? '' }}">
        <h2 class="box-title">Путь сделки</h2>
        <div class="mt-3"><x-route.timeline :blocks="$blocks" :current="$currentBlock" :steps="$steps" :waiting="$waiting" :deal="$deal"/></div>
    </div>
@endif
