{{-- Подпись на телефоне: холст в карточке, PNG уходит полем `signature`; «Заново» — стереть. --}}
@props(['name' => 'signature', 'label' => 'Подпись'])
<div class="signature col-span-full" data-controller="signature">
    <div class="flex items-center justify-between">
        <span class="field-label">{{ $label }}</span>
        <button type="button" class="btn btn-ghost btn-s" data-action="signature#clear">Заново</button>
    </div>
    <canvas class="signature-pad" data-signature-target="canvas" data-action="pointerdown->signature#start pointermove->signature#move pointerup->signature#end pointercancel->signature#end pointerleave->signature#end"></canvas>
    <input type="hidden" name="{{ $name }}" data-signature-target="input">
</div>
