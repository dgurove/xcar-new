{{-- Кнопка «Письма N»: открывает окно писем x-mail.window (событие letters:open). chip — чипом в ряду, иначе кнопка. --}}
@props(['count', 'chip' => false])
<button type="button" class="{{ $chip ? 'chip' : 'btn btn-quiet' }}" data-controller="emit" data-action="emit#send" data-emit-event-param="letters:open" {{ $attributes }}><x-ui.icon name="mail" class="{{ $chip ? 'size-4' : 'size-5' }}"/> Письма <span class="nums">{{ $count }}</span></button>
