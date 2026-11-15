{{-- Значение, которое копируют, — видом поля, а не голым текстом (15.11.2026, владелец: «поля текста, из которых копирование
     происходит, выглядят как поля, а не просто текст, который написан»): рамка поля, текст, значок копирования справа; нажатие —
     в буфер (`copy_controller`, тост `done`). label — подпись над полем, как у полей формы. value — что копировать, иначе слот. --}}
@props(['value' => null, 'label' => null, 'done' => 'Скопировано'])
@php $text = $value ?? trim(strip_tags((string) $slot)); @endphp
<div {{ $attributes->class('field') }}>
    @if ($label)<span class="field-label">{{ $label }}</span>@endif
    <button type="button" class="copy-field" data-controller="copy" data-copy-text-value="{{ $text }}" data-copy-done-value="{{ $done }}" data-action="copy#copy" title="Скопировать">
        <span class="min-w-0 flex-1 break-words text-left">{{ trim($slot) !== '' ? $slot : $value }}</span>
        <x-ui.icon name="copy" class="mt-0.5 size-4 shrink-0 text-ink-muted"/>
    </button>
</div>
