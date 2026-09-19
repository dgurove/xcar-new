{{-- Кнопка «Письма N» для телефона: открывает шторку x-mail.aside, от 1024 не нужна — письма и так рядом. --}}
@props(['count', 'chip' => false])
<button type="button" class="{{ $chip ? 'chip' : 'btn btn-quiet' }} lg:hidden" data-controller="emit" data-action="emit#send" data-emit-event-param="letters:open" {{ $attributes }}><x-ui.icon name="mail" class="{{ $chip ? 'size-4' : 'size-5' }}"/> Письма <span class="nums">{{ $count }}</span></button>
