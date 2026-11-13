{{-- Кнопка дела «Ваш ход» (`Position::todo`): окно писем, ссылка или звонок. group — кнопка группы (лаймовая, всегда с подписью).
     wait — сколько ждёт (`x-deal.todo-row`): на телефоне кнопка — само время «3д 21ч» вместо подписи (13.11.2026, владелец: строка в
     две строки, а не в три), одной ширины у всех строк, чтобы не скакали; просрочено — время красным. --}}
@props(['button', 'group' => false, 'wait' => null, 'late' => false])
@php
    $class = 'btn btn-s '.($group ? 'btn-accent' : 'btn-quiet');
    $timed = ! $group && $wait;
    $class .= $timed ? ' todo-btn'.($late ? ' text-danger' : '') : '';
    $round = $group || $timed ? '' : ' max-sm:aspect-square max-sm:!px-0';
    $time = $timed ? '<span class="nums sm:hidden" data-controller="timer" data-timer-since-value="'.$wait->toIso8601String().'" data-timer-coarse-value="true"></span>' : '';
@endphp
@if (isset($button['letters']))
    <button type="button" data-controller="emit" data-action="emit#send" data-emit-event-param="letters:open" data-emit-url-param="{{ $button['letters'] }}" class="{{ $class.$round }}" aria-label="{{ $button['label'] }}"><x-ui.icon name="mail" @class(['size-5', 'max-sm:hidden' => $timed])/><span @class(['max-sm:hidden' => ! $group])>{{ $button['label'] }}</span>{!! $time !!}</button>
@elseif (isset($button['tel']))
    {{-- Номер менеджера — номером, не кнопкой «Позвонить» (11.11.2026): нажатие — «Скопировать / Позвонить» (`tel.js`). --}}
    <x-ui.tel :value="$button['tel']" class="text-sm"/>
@elseif (isset($button['href']))
    <a href="{{ $button['href'] }}" data-turbo-frame="_top" class="{{ $class }}" aria-label="{{ $button['label'] }}"><span @class(['max-sm:hidden' => $timed])>{{ $button['label'] }}</span>{!! $time !!}</a>
@endif
