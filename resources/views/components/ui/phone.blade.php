{{-- Телефон человека — номером (`x-ui.tel`): нажатие — «Скопировать / Позвонить». --}}
@props(['user'])
<x-ui.tel :value="$user->phone" {{ $attributes }}/>
