@php use App\Park\{RequestState, ReleasedTo, Inspection}; use App\Support\Money; @endphp
{{-- Раскрытие сделанного шага: что записали, фото, акт; письмо — окно. --}}@php $r = $step->request; $insp = $r ? $vehicle->inspections->firstWhere('request_id', $r->id) : null; $stage = $step->key === 'release' ? 'release' : 'intake'; $photos = in_array($step->key, ['intake', 'release'], true) ? $vehicle->photos()->filter(fn ($m) => \App\Park\PhotoStage::of($m)->value === $stage) : collect(); @endphp
<div class="flex flex-col gap-3 pb-2 pt-1">
    @if ($step->key === 'letter' && $letters)<div><x-mail.window-button :count="$letters" :url="'/cars/'.$vehicle->id.'/letters'" chip/></div>@endif
    @if ($r?->note && $step->key !== 'letter')<p class="whitespace-pre-line text-sm text-ink-muted">{{ $r->note }}</p>@endif
    @if ($insp)
        <div class="flex flex-wrap items-center gap-1.5">
            @if ($insp->keys_count !== null)<span class="tag nums">ключей {{ $insp->keys_count }}</span>@endif
            @foreach ($insp->docs ?? [] as $d)<span class="tag">{{ Inspection::DOCS[$d] ?? $d }}</span>@endforeach
            <x-ui.doc :doc="['url' => '/acts/'.$vehicle->id.'/'.$stage, 'type' => 'html', 'name' => $stage === 'release' ? 'Акт выдачи' : 'Акт приёма']" class="tag">Акт</x-ui.doc>
        </div>
    @endif
    @if ($photos->isNotEmpty())
        <div data-controller="photos" data-photos-readonly-value="true" data-photos-group-value="car-{{ $vehicle->id }}" data-photos-order="{{ array_search(\App\Park\PhotoStage::from($stage), \App\Park\PhotoStage::cases(), true) }}"><x-ui.photos :photos="$photos" readonly grid :hide="false" :main="false" id="phase-{{ $step->key }}"/></div>
    @endif
</div>
