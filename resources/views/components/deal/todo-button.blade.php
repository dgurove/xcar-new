{{-- Кнопка дела «Ваш ход» (`Position::todo`): окно писем, ссылка или звонок. В строке на телефоне — одним значком: подпись
     съедала название ТС. group — кнопка группы (лаймовая, всегда с подписью). --}}
@props(['button', 'group' => false])
@php $class = 'btn btn-s '.($group ? 'btn-accent' : 'btn-quiet'); $round = $group ? '' : ' max-sm:aspect-square max-sm:!px-0'; @endphp
@if (isset($button['letters']))
    <button type="button" data-controller="emit" data-action="emit#send" data-emit-event-param="letters:open" data-emit-url-param="{{ $button['letters'] }}" class="{{ $class.$round }}" aria-label="{{ $button['label'] }}"><x-ui.icon name="mail" class="size-5"/><span @class(['max-sm:hidden' => ! $group])>{{ $button['label'] }}</span></button>
@elseif (isset($button['tel']))
    <a href="tel:{{ $button['tel'] }}" class="{{ $class }}">{{ $button['label'] }}</a>
@elseif (isset($button['href']))
    <a href="{{ $button['href'] }}" data-turbo-frame="_top" class="{{ $class }}">{{ $button['label'] }}</a>
@endif
