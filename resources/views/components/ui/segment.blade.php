{{-- Сегмент из радиокнопок: options — значение => надпись, value — отмеченное. pick — переключает панели reveal
     (data-reveal-key), reveal — значение => data-reveal-label (надпись кнопки формы под выбранное). --}}
@props(['name', 'options', 'value' => null, 'pick' => false, 'reveal' => []])
<div {{ $attributes->merge(['class' => 'segment']) }}>
    @foreach ($options as $key => $label)
        <label><input type="radio" name="{{ $name }}" value="{{ $key }}" @checked((string) $value === (string) $key) @if ($pick) data-action="reveal#pick" @endif @isset($reveal[$key]) data-reveal-label="{{ $reveal[$key] }}" @endisset><span>{{ $label }}</span></label>
    @endforeach
</div>
