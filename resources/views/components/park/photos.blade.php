{{-- Кадры одной стадии карточкой: от страховой, при приёме, при выдаче. Имя — из PhotoStage, `id` — чтобы ответ
     загрузки подменял именно эту карточку. edit — можно добавлять (плитка с камерой), collapsed — блок свёрнут
     («Фото при выдаче»: чаще всего выдачу не снимают, но при желании открывают и кладут кадры). --}}
@props(['vehicle', 'stage', 'edit' => false, 'nested' => false, 'collapsed' => false])
@php
    use App\Park\PhotoStage;
    $photos = $vehicle->photos()->filter(fn ($m) => PhotoStage::of($m) === $stage)->values();
    $n = $photos->count();
@endphp
<x-ui.card :nested="$nested" :id="'photos-'.$stage->value" {{ $attributes->merge(['data-controller' => 'photos'] + ($edit
    ? ['data-photos-url-value' => '/cars/'.$vehicle->id.'/media', 'data-photos-stage-value' => $stage->value]
    : ['data-photos-readonly-value' => 'true'])) }}>
    @if ($collapsed)
        <details class="step-details" @if ($n) open @endif>
            <summary class="flex items-center gap-2">
                <h2 class="text-xl">{{ $stage->label() }}@if ($n) <span class="nums text-base font-normal text-ink-dim">{{ $n }}</span>@endif</h2>
                <x-ui.icon name="chevron-down" class="step-chevron size-5 text-ink-dim"/>
            </summary>
            <div class="mt-4">@include('park.vehicles.photo-body')</div>
        </details>
    @else
        <div class="mb-4 flex flex-wrap items-center gap-x-3 gap-y-1.5">
            <h2 class="text-xl">{{ $stage->label() }}@if ($n) <span class="nums text-base font-normal text-ink-dim">{{ $n }}</span>@endif</h2>
        </div>
        @include('park.vehicles.photo-body')
    @endif
</x-ui.card>
