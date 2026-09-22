{{-- VIN на экране — x-ui.copy-code с тостом «VIN в буфере». Скрытый VIN (звёздочки, copy=false) — просто текст. --}}
@props(['vin', 'copy' => true])
@if ($vin)
    @if ($copy && !str_contains($vin, '*'))
        <x-ui.copy-code :value="$vin" done="VIN в буфере" title="Скопировать VIN" {{ $attributes }}/>
    @else
        <span {{ $attributes->merge(['class' => 'nums']) }}>{{ $vin }}</span>
    @endif
@endif
