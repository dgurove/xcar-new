{{-- Кадры одной стадии карточкой: от страховой, при приёме, при выдаче. Имя — из PhotoStage, `id` — чтобы ответ
     загрузки подменял именно эту карточку. edit — можно добавлять (плитка с камерой), collapsed — блок свёрнут
     («Фото при выдаче»: чаще всего выдачу не снимают, но при желании открывают и кладут кадры). --}}
@props(['vehicle', 'stage', 'edit' => false, 'nested' => false, 'collapsed' => false])
@php
    use App\Park\PhotoStage;
    $photos = $vehicle->photos()->filter(fn ($m) => PhotoStage::of($m) === $stage)->values();
    $n = $photos->count();
    // Кадры письма, которые ещё прикрепляются, — заглушками в «От страховой».
    $pending = $stage === PhotoStage::Vendor ? \App\Mail\Jobs\ImportThreadFiles::pending($vehicle) : 0;
@endphp
<x-ui.card :nested="$nested" :id="'photos-'.$stage->value" {{ $attributes->merge(['data-controller' => 'photos', 'data-photos-group-value' => 'car-'.$vehicle->id, 'data-photos-order' => array_search($stage, PhotoStage::cases(), true)] + ($edit
    ? ['data-photos-url-value' => '/cars/'.$vehicle->id.'/media', 'data-photos-stage-value' => $stage->value]
    : ['data-photos-readonly-value' => 'true', 'data-photos-url-value' => '/cars/'.$vehicle->id.'/media'])) }}>
    @if ($collapsed)
        <details class="step-details" @if ($n) open @endif>
            <summary class="flex items-center gap-2">
                <h2 class="box-title">{{ $stage->label() }}@if ($n) <span class="nums">{{ $n }}</span>@endif</h2>
                <x-ui.icon name="chevron-down" class="step-chevron size-5 text-ink-dim"/>
            </summary>
            <div class="mt-4">@include('park.vehicles.photo-body')</div>
        </details>
    @else
        {{-- Подписью раздела, как «Документы» и «Оплаты» рядом (20.10.2026): крупным — сами кадры. Пусто — строка
             «подпись … + Фото», без плитки с камерой во всю карточку (два пустых блока стояли столбом). --}}
        @php $blank = $edit && ! $n && ! $pending; @endphp
        <div @class(['flex flex-wrap items-center gap-x-3 gap-y-1.5', 'mb-3' => ! $blank])>
            <h2 class="box-title !mb-0">{{ $stage->label() }}@if ($n) <span class="nums">{{ $n }}</span>@endif</h2>
            @if ($blank)<x-ui.button type="button" variant="secondary" size="sm" class="ml-auto" data-action="photos#pick"><x-ui.icon name="plus" class="size-4"/> Фото</x-ui.button>
            @elseif (auth()->user()->isAdmin())<x-ui.photos-all class="ml-auto"/>@endif
        </div>
        @include('park.vehicles.photo-body')
    @endif
</x-ui.card>
