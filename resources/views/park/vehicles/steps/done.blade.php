@php use App\Park\{RequestState, ReleasedTo, Inspection}; use App\Support\Money; @endphp
{{-- Раскрытие сделанного шага: что записали, фото, подпись, акт; звонок — «Позвонить снова», письмо — окно. --}}@php $r = $step->request; $insp = $r ? $vehicle->inspections->firstWhere('request_id', $r->id) : null; $stage = $step->key === 'release' ? 'release' : 'intake'; $photos = in_array($step->key, ['intake', 'release'], true) ? $vehicle->photos()->filter(fn ($m) => \App\Park\PhotoStage::of($m)->value === $stage) : collect(); @endphp
<div class="flex flex-col gap-3 pb-2 pt-1">
    @if ($step->key === 'letter' && $letters)<div><x-mail.window-button :count="$letters" :url="'/cars/'.$vehicle->id.'/letters'" chip/></div>@endif
    @if ($step->key === 'call' && $r?->isOpen())<div><a href="/cars/{{ $vehicle->id }}?call=1" class="chip"><x-ui.icon name="phone" class="size-4"/> Позвонить снова</a></div>@endif
    @if ($step->key === 'tow' && $r)
        <div class="flex flex-wrap gap-1.5">
            @if ($r->from_address)<x-ui.place class="tag">{{ $r->from_address }}</x-ui.place>@endif
            @if ($r->yard)<x-ui.place class="tag">→ {{ $r->yard->name }}</x-ui.place>@endif
        </div>
    @endif
    @if ($r?->note && $step->key !== 'letter')<p class="whitespace-pre-line text-sm text-ink-muted">{{ $r->note }}</p>@endif
    @if ($insp)
        <div class="flex flex-wrap items-center gap-1.5">
            @if ($insp->keys_count !== null)<span class="tag nums">ключей {{ $insp->keys_count }}</span>@endif
            @foreach ($insp->docs ?? [] as $d)<span class="tag">{{ Inspection::DOCS[$d] ?? $d }}</span>@endforeach
            @if ($insp->signer_name)<span class="tag">{{ $insp->signer_name }}</span>@endif
            @if ($sig = $insp->signatureDataUrl())<img src="{{ $sig }}" alt="Подпись" class="h-10 rounded bg-white px-2">@endif
            <a href="/acts/{{ $vehicle->id }}/{{ $stage }}" class="tag" data-turbo="false" target="_blank">Акт</a>
        </div>
    @endif
    @if ($photos->isNotEmpty())
        <div data-controller="photos" data-photos-readonly-value="true"><x-ui.photos :photos="$photos" readonly grid :hide="false" :main="false" id="phase-{{ $step->key }}"/></div>
    @endif
</div>
