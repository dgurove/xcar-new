{{-- «История» машины — одна лента на страницу предложения, лот закупки, дело и окно ТС парковки (11.11.2026: было три
     разметки, у окна парковки — своя строкой «дата текст кто»): первые четыре записи, остальное по «Ещё N» (лента в тридцать
     строк занимала экран); строка — `x-car.history-row`. --}}
@props(['events', 'limit' => 4])
@if ($events->isNotEmpty())
<div {{ $attributes->merge(['class' => 'flex flex-col gap-3 text-sm']) }}>
    @foreach ($events->take($limit) as $e)<x-car.history-row :event="$e"/>@endforeach
    @if ($events->count() > $limit)
        <details class="group flex flex-col gap-3">
            <summary class="cursor-pointer list-none text-ink-muted group-open:hidden">Ещё {{ $events->count() - $limit }}</summary>
            <div class="flex flex-col gap-3">
                @foreach ($events->slice($limit) as $e)<x-car.history-row :event="$e"/>@endforeach
            </div>
        </details>
    @endif
</div>
@endif
