{{-- Кнопка окна действия (`x-park.act-window`): «Принять», «Выдать», «Отправить акт приёма». step — шаг окна, advance —
     открыли из «Заявок»: после окна карточка к следующей заявке; chip — чипом в шаге таймлайна. --}}
@props(['vehicle', 'step', 'label', 'advance' => false, 'chip' => false])
<button type="button" {{ $attributes->merge(['class' => $chip ? 'chip' : 'btn btn-accent']) }} data-controller="emit" data-action="emit#send" data-emit-event-param="act:open"
    data-emit-url-param="/cars/{{ $vehicle->id }}/window/{{ $step }}{{ $advance ? '?advance=1' : '' }}">{{ $label }}</button>
