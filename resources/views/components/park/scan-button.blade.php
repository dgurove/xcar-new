{{-- «✨ Из документов» — окно «Распознать» у дела ТС (x-mail.scan-window на странице): прочитать документы писем и
     подставить в карточку. Стоит у полей, которые заполняет. --}}
@props(['vehicle'])
<button type="button" {{ $attributes->merge(['class' => 'chip shrink-0']) }} data-controller="emit" data-action="emit#send" data-emit-event-param="scan:open" data-emit-url-param="/cars/{{ $vehicle->id }}/scan"><span aria-hidden="true">✨</span>Из документов</button>
