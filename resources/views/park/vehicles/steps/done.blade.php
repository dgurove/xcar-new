@php use App\Park\{RequestState, ReleasedTo, Inspection}; use App\Support\Money; @endphp
{{-- Раскрытие сделанного шага: что записали, фото, акт; письмо — окно. --}}@php $r = $step->request; $insp = $r ? $vehicle->inspections->firstWhere('request_id', $r->id) : null; $stage = $step->key === 'release' ? 'release' : 'intake'; $photos = in_array($step->key, ['intake', 'release'], true) ? $vehicle->photos()->filter(fn ($m) => \App\Park\PhotoStage::of($m)->value === $stage) : collect(); @endphp
<div class="flex flex-col gap-3 pb-2 pt-1">
    @if ($step->key === 'letter' && $letters)<div><x-mail.window-button :count="$letters" :url="'/cars/'.$vehicle->id.'/letters'" chip/></div>@endif
    @if ($r?->note && $step->key !== 'letter')<p class="whitespace-pre-line text-sm text-ink-muted">{{ $r->note }}</p>@endif
    @if ($insp)
        {{-- Что записали — строкой текста («ключей 2, СТС, ПТС»), акт — документом справа (10.10.2026: «что-то чипами, что-то
             текстом»; это факты, а не коды). --}}
        <div class="flex items-baseline gap-3 text-sm">
            @php $wrote = array_filter([$insp->keys_count !== null ? 'ключей '.$insp->keys_count : null, ...array_map(fn ($d) => Inspection::DOCS[$d] ?? $d, $insp->docs ?? [])]); @endphp
            @if ($wrote)<span class="nums min-w-0 flex-1 text-ink-muted">{{ implode(', ', $wrote) }}</span>@endif
            <x-ui.doc :doc="['url' => '/acts/'.$vehicle->id.'/'.$stage, 'type' => 'html', 'name' => $stage === 'release' ? 'Акт выдачи' : 'Акт приёма']" class="{{ $wrote ? '' : 'ml-0' }} shrink-0 text-accent-text">{{ $stage === 'release' ? 'Акт выдачи' : 'Акт приёма' }}</x-ui.doc>
        </div>
    @endif
    @if ($photos->isNotEmpty())
        <div data-controller="photos" data-photos-readonly-value="true" data-photos-group-value="car-{{ $vehicle->id }}" data-photos-order="{{ array_search(\App\Park\PhotoStage::from($stage), \App\Park\PhotoStage::cases(), true) }}"><x-ui.photos :photos="$photos" readonly grid :hide="false" :main="false" id="phase-{{ $step->key }}"/></div>
    @endif
</div>
