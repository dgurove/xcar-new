{{-- Кнопка «Письма N»: открывает окно писем x-mail.window (событие letters:open с url). chip — чипом в ряду, иначе кнопка. --}}
@props(['count' => null, 'url', 'chip' => false, 'label' => 'Письма'])
<button type="button" class="{{ $chip ? 'chip' : 'btn btn-quiet' }}" data-controller="emit" data-action="emit#send" data-emit-event-param="letters:open" data-emit-url-param="{{ $url }}" {{ $attributes }}><x-ui.icon name="mail" class="{{ $chip ? 'size-4' : 'size-5' }}"/>@if ($label !== '') {{ $label }}@endif @if ($count !== null) <span class="nums">{{ $count }}</span>@endif</button>
