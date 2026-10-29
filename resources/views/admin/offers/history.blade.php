{{-- Лента «Истории» машины: четыре записи, остальное по «Ещё N» (лента в тридцать строк занимала экран). Редактор
     предложения и карточка лота закупки — одна лента `car_events` (29.10.2026). --}}
<div class="flex flex-col gap-3 text-sm">
    @foreach ($events->take(4) as $e)@include('admin.offers.event', ['event' => $e])@endforeach
    @if ($events->count() > 4)
        <details class="group flex flex-col gap-3">
            <summary class="cursor-pointer list-none text-ink-muted group-open:hidden">Ещё {{ $events->count() - 4 }}</summary>
            <div class="flex flex-col gap-3">
                @foreach ($events->slice(4) as $e)@include('admin.offers.event', ['event' => $e])@endforeach
            </div>
        </details>
    @endif
</div>
